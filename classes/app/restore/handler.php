<?php

namespace block_sharing_cart\app\restore;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory as base_factory;
use block_sharing_cart\app\item\entity;
use block_sharing_cart\task\asynchronous_restore_task;

class handler
{
    private base_factory $base_factory;

    public function __construct(base_factory $base_factory)
    {
        $this->base_factory = $base_factory;
    }

    public function restore_item_into_section(
        entity $item,
        int $section_id,
        int $item_id,
        array $settings = []
    ): asynchronous_restore_task|null {
        global $USER, $DB;

        $course_id = $DB->get_field('course_sections', 'course', ['id' => $section_id], MUST_EXIST);

        $settings['move_to_section_id'] = $section_id;

        $backup_file = $this->base_factory->item()->repository()->get_stored_file_by_item($item);
        if (!$backup_file) {
            throw new \Exception('Backup file not found for item (id: ' . $item->get_id() . ')');
        }

        if(!$this->restore_is_valid($item_id, $section_id)) return null;

        // NEW: If restoring a subsection, auto-include its child module IDs
        if ($item->is_subsection()) {
            $settings = $this->auto_include_subsection_children($settings, $backup_file);
        }

        $restore_controller = $this->base_factory->restore()->restore_controller(
            $backup_file,
            $course_id,
            $USER->id
        );

        return $this->queue_async_restore($restore_controller, $item, $settings);
    }

    /**
     * When restoring a subsection, automatically include all child module IDs.
     * This prevents the subsection from being restored empty.
     */
    private function auto_include_subsection_children(
        array $settings,
        \stored_file $backup_file
    ): array {
        try {
            // Get the backup tree to extract module IDs
            $backup_tree = $this->base_factory->backup()->handler()->get_backup_item_tree($backup_file);

            // Extract all module IDs from the tree
            $child_module_ids = $this->extract_all_module_ids_from_tree($backup_tree);

            if (!empty($child_module_ids)) {
                // Add or merge with existing course_modules_to_include
                $existing_modules = $settings['course_modules_to_include'] ?? [];
                $settings['course_modules_to_include'] = array_unique(
                    array_merge($existing_modules, $child_module_ids)
                );
            }
        } catch (\Exception $e) {
            // If extraction fails, log it but don't break the restore
            mtrace("Warning: Could not auto-include subsection children: " . $e->getMessage());
        }

        return $settings;
    }

    /**
     * Recursively extract all module IDs from the backup tree.
     * This includes modules nested under subsections.
     */
    private function extract_all_module_ids_from_tree(array $tree): array {
        $module_ids = [];

        foreach ($tree as $section) {
            if (!isset($section->activities)) {
                continue;
            }

            foreach ($section->activities as $activity) {
                // If this is a subsection, extract its nested activities
                if (isset($activity->modulename) && $activity->modulename === 'subsection') {
                    if (isset($activity->subsection_activities)) {
                        foreach ($activity->subsection_activities as $subsection_activity) {
                            if (isset($subsection_activity->moduleid)) {
                                $module_ids[] = (int)$subsection_activity->moduleid;
                            }
                        }
                    }
                } else {
                    // Regular activity
                    if (isset($activity->moduleid)) {
                        $module_ids[] = (int)$activity->moduleid;
                    }
                }
            }
        }

        return array_filter($module_ids); // Remove any falsy values
    }

    /**
     * Restores are valid and to be queued only if they are valid according to the conditions in the function body.
     * The UI presents the user with the options of where to restore the item copied from the clipboard.
     * This function is the backend check of that logic.
     */
    private function restore_is_valid(int $item_id, int $target_section_id) : bool{

        global $DB;

        $target_section = $DB->get_record('course_sections', ['id' => $target_section_id], MUST_EXIST);

        $sql = "SELECT
                I1.type AS own_type
                ,I2.type AS parent_type
                FROM {block_sharing_cart_items} AS I1
                LEFT JOIN {block_sharing_cart_items} AS I2 ON I1.parent_item_id = I2.id
                WHERE I1.id = :item_id";
        $params = [
            'item_id' => $item_id,
        ];

        $subject_item = $DB->get_record_sql($sql,$params,MUST_EXIST);

        $is_target_a_section = empty($target_section->component) && empty($target_section->itemid);
        $is_target_a_subsection = !empty($target_section->component) && $target_section->component == 'mod_subsection';

        //Attempt to restore a section into a non-section?
        if(!$is_target_a_section){
            if($subject_item->own_type === 'section'){return false;}
        }

        //Attempt to restore a subsection into a subsection?
        if($is_target_a_subsection){
            if($subject_item->own_type === 'mod_subsection'){return false;}

            //Attempt to restore a subsections's child into a subsection?
            if($subject_item->parent_type === 'subsection'){return false;}
        }

        return true;
    }

    private function queue_async_restore(
        \restore_controller $restore_controller,
        entity $item,
        array $settings = []
    ): asynchronous_restore_task {
        $asynctask = new asynchronous_restore_task();
        $asynctask->set_custom_data([
            'backupid' => $restore_controller->get_restoreid(),
            'item' => $item->to_array(),
            'course_id' => $restore_controller->get_courseid(),
            'backup_settings' => $settings
        ]);
        $asynctask->set_userid($restore_controller->get_userid());
        \core\task\manager::queue_adhoc_task($asynctask);

        return $asynctask;
    }
}
