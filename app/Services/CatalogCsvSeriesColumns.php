<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

/**
 * Reserved, editable Series-page columns for the ONE Catalog CSV importer.
 * All keys are stored in existing series_metadata field/value tables;
 * a filled *_media_id column resolves an already-uploaded Media Library file.
 */
final class CatalogCsvSeriesColumns
{
    private const LEGACY_FEATURE_COLUMNS = ['series_feature_1', 'series_feature_2', 'series_feature_3'];

    /** @return array<string, array{0:string,1:string,2:string,3?:string}> */
    public static function definitions(bool $includeLegacyFeatureColumns = true): array
    {
        $definitions = [
            // Introduction and key summary
            'series_notes' => ['series_notes', 'Series Introduction', 'text'],
            'series_features' => ['series_features', 'Series Features', 'text'],
            'series_product_type' => ['series_product_type', 'Product Type', 'text'],
            'series_test_frequency' => ['series_test_frequency', 'Test Frequency', 'text'],
            'series_operating_temperature' => ['series_operating_temperature', 'Operating Temperature', 'text'],
            'series_spq_per_reel' => ['series_spq_per_reel', 'SPQ per Reel', 'number'],
            'series_image_media_id' => ['series_product_image', 'Series Product Image', 'file', 'image'],
            'series_3d_media_id' => ['series_model_3d', 'Series 3D Model', 'file', 'glb'],
            'series_pdf_media_id' => ['series_product_spec', 'Series Datasheet PDF', 'file', 'pdf'],
            'image_width_px' => ['image_width_px', 'Image Width (px)', 'number'],
            'image_height_px' => ['image_height_px', 'Image Height (px)', 'number'],
            'compliance_rohs_reach' => ['compliance_rohs_reach', 'RoHS / REACH', 'text'],
            'compliance_halogen_free' => ['compliance_halogen_free', 'Halogen Status', 'text'],
            'compliance_aec_q200' => ['compliance_aec_q200', 'AEC-Q200 Status', 'text'],
            // Environmental
            'environment_operating_temperature' => ['environment_operating_temperature', 'Operating Temperature', 'text'],
            'environment_storage_temperature' => ['environment_storage_temperature', 'Storage Temperature', 'text'],
            'environment_test_ambient' => ['environment_test_ambient', 'Electrical Data Test Ambient', 'text'],
            'environment_storage_instructions' => ['environment_storage_instructions', 'Storage Instructions', 'text'],
            'environment_max_storage_temp' => ['environment_max_storage_temp', 'Recommended Storage Temperature', 'text'],
            'environment_max_humidity' => ['environment_max_humidity', 'Recommended Storage Humidity', 'text'],
            'environment_notes' => ['environment_notes', 'Environmental Notes', 'text'],
            // Performance curves (these are Series curves; part curve can vary by Part Number)
            'performance_curve_title' => ['performance_curve_title', 'Curve Title', 'text'],
            'performance_curve_description' => ['performance_curve_description', 'Curve Description', 'text'],
            'performance_curve_1_image_media_id' => ['performance_curve_1_image', 'Performance Curve 1', 'file', 'image'],
            'performance_curve_1_caption' => ['performance_curve_1_caption', 'Performance Curve 1 Caption', 'text'],
            'performance_curve_2_image_media_id' => ['performance_curve_2_image', 'Performance Curve 2', 'file', 'image'],
            'performance_curve_2_caption' => ['performance_curve_2_caption', 'Performance Curve 2 Caption', 'text'],
            'performance_curve_3_image_media_id' => ['performance_curve_3_image', 'Performance Curve 3', 'file', 'image'],
            'performance_curve_3_caption' => ['performance_curve_3_caption', 'Performance Curve 3 Caption', 'text'],
            'performance_test_conditions' => ['performance_test_conditions', 'Curve Test Conditions', 'text'],
            'performance_notes' => ['performance_notes', 'Performance Notes', 'text'],
            // Physical characteristics, drawing coordinates are millimeters
            'physical_dimension_drawing_media_id' => ['physical_dimension_drawing', 'Dimension Drawing', 'file', 'image'],
            'physical_pcb_layout_media_id' => ['physical_pcb_layout', 'PCB Layout Drawing', 'file', 'image'],
            'physical_dimension_notes' => ['physical_dimension_notes', 'Dimension Drawing Notes', 'text'],
            'physical_pcb_notes' => ['physical_pcb_notes', 'PCB Layout Notes', 'text'],
            'physical_dimension_a_mm' => ['physical_dimension_a_mm', 'Dimension A (mm)', 'text'],
            'physical_dimension_b_mm' => ['physical_dimension_b_mm', 'Dimension B (mm)', 'text'],
            'physical_dimension_c_mm' => ['physical_dimension_c_mm', 'Dimension C (mm)', 'text'],
            'physical_dimension_d1_mm' => ['physical_dimension_d1_mm', 'Dimension D1 (mm)', 'text'],
            'physical_dimension_d2_mm' => ['physical_dimension_d2_mm', 'Dimension D2 (mm)', 'text'],
            'physical_dimension_p_mm' => ['physical_dimension_p_mm', 'Dimension P (mm)', 'text'],
            'physical_pcb_g_mm' => ['physical_pcb_g_mm', 'PCB G (mm)', 'text'],
            'physical_pcb_h_mm' => ['physical_pcb_h_mm', 'PCB H (mm)', 'text'],
            'physical_pcb_i_mm' => ['physical_pcb_i_mm', 'PCB I (mm)', 'text'],
            'physical_pcb_j_mm' => ['physical_pcb_j_mm', 'PCB J (mm)', 'text'],
            'physical_pcb_l_mm' => ['physical_pcb_l_mm', 'PCB L (mm)', 'text'],
            // Tape and reel
            'tape_chip_per_reel' => ['tape_chip_per_reel', 'Chips per Reel', 'number'],
            'tape_inner_box_qty' => ['tape_inner_box_qty', 'Inner Box Quantity', 'number'],
            'tape_middle_box_qty' => ['tape_middle_box_qty', 'Middle Box Quantity', 'number'],
            'tape_carton_qty' => ['tape_carton_qty', 'Carton Quantity', 'number'],
            'tape_reel_size' => ['tape_reel_size', 'Reel Size', 'text'],
            'tape_width_mm' => ['tape_width_mm', 'Tape Width (mm)', 'text'],
            'tape_drawing_media_id' => ['tape_drawing', 'Tape and Reel Drawing', 'file', 'image'],
            'tape_tear_force' => ['tape_tear_force', 'Tear-Off Force', 'text'],
            'tape_notes' => ['tape_notes', 'Tape and Reel Notes', 'text'],
            // Soldering / washing
            'solder_reflow_profile_media_id' => ['solder_reflow_profile', 'Reflow Profile Image', 'file', 'image'],
            'solder_reflow_max_times' => ['solder_reflow_max_times', 'Reflow Maximum Cycles', 'number'],
            'solder_reflow_standard' => ['solder_reflow_standard', 'Reflow Standard', 'text'],
            'solder_hand_notes' => ['solder_hand_notes', 'Hand Soldering Notes', 'text'],
            'solder_washing_notes' => ['solder_washing_notes', 'Washing Notes', 'text'],
            'solder_process_notes' => ['solder_process_notes', 'Soldering Process Notes', 'text'],
            // Downloads and support
            'download_brochure_pdf_media_id' => ['download_brochure_pdf', 'Product Brochure PDF', 'file', 'pdf'],
            'download_application_note_pdf_media_id' => ['download_application_note_pdf', 'Application Note PDF', 'file', 'pdf'],
            'download_datasheet_description' => ['download_datasheet_description', 'Datasheet Description', 'text'],
            'inquiry_support_description' => ['inquiry_support_description', 'Inquiry Help Text', 'text'],
        ];

        if ($includeLegacyFeatureColumns) {
            foreach (self::LEGACY_FEATURE_COLUMNS as $index => $key) {
                $definitions[$key] = [$key, 'Feature ' . ($index + 1), 'text'];
            }
        }
        return $definitions;
    }

    public static function isFeatureList(string $value): bool
    {
        // Allow a single list with text and simple inline formatting, without attributes.
        return strlen($value) <= 65535
            && preg_match('~\A<(ul|ol)>\s*(?:<li>(?:[^<]++|</?(?:strong|em|b|i)>|<br\s*/?>)*+</li>\s*)++</\1>\z~iu', $value) === 1;
    }

    public static function legacyFeatureList(array $metadata): string
    {
        $items = [];
        foreach (self::LEGACY_FEATURE_COLUMNS as $key) {
            $value = trim((string) ($metadata[$key] ?? ''));
            if ($value !== '') {
                $items[] = '<li>' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
        }
        return $items === [] ? '' : '<ul>' . implode('', $items) . '</ul>';
    }
}
