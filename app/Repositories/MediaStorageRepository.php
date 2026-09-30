<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/**
 * Reads catalog names needed to build media storage paths.
 */
final class MediaStorageRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Loads the series and parent category names.
     *
     * @return array<string, mixed>|null
     */
    public function findSeriesContext(int $seriesId): ?array
    {
        $stmt = $this->connection->prepare(
            "SELECT s.id, s.name AS series_name, c.name AS category_name
             FROM category s
             LEFT JOIN category c ON s.parent_id = c.id
             WHERE s.id = ? AND s.type = 'series'
             LIMIT 1"
        );
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }
}
