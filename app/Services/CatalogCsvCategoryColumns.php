<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

/** Category-level content for the unified Catalog CSV Import/Export. */
final class CatalogCsvCategoryColumns
{
    /** @return array<string, array{0:string,1:string}> */
    public static function definitions(): array
    {
        return [
            'category_introduction' => ['Category Introduction', 'text'],
            'category_hero_image_media_id' => ['Category Hero Image 560 x 320', 'image'],
            'category_hero_image_alt' => ['Hero Image Alt Text', 'text'],
            'category_banner_image_media_id' => ['Category Banner 1320 x 420', 'image'],
            'category_banner_description' => ['Category Banner Description', 'text'],
            'category_banner_image_alt' => ['Banner Image Alt Text', 'text'],
            'category_icon_image_media_id' => ['Category Icon Image', 'image'],
        ];
    }
}
