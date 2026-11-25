<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

/** Optional per-part image, PDF and performance-curve columns. */
final class CatalogCsvProductColumns
{
    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function definitions(): array
    {
        return [
            'product_image_media_id' => ['product_image', 'Part Image', 'image'],
            'product_pdf_media_id' => ['product_spec', 'Part Datasheet PDF', 'pdf'],
            'product_curve_image_media_id' => ['product_curve_image', 'Part Performance Curve', 'image'],
            'product_curve_pdf_media_id' => ['product_curve_pdf', 'Part Performance Curve PDF', 'pdf'],
        ];
    }
}
