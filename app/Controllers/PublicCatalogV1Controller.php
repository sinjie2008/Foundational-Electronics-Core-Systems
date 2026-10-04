<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\CatalogPartsQuery;
use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Transport;
use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Support\Db;
use Throwable;

/** A read-only dispatcher, independent of legacy bootstrap and future authentication adapters. */
final class PublicCatalogV1Controller
{
    public function __construct(private ?CatalogV1Service $service = null)
    {
    }

    public function run(): void
    {
        $responder = new HttpResponder();
        $responder->setCorrelationId(Request::correlationId());
        try {
            Transport::header('Cache-Control: no-store');
            Transport::header('X-Content-Type-Options: nosniff');
            if (Request::method() !== 'GET') {
                Transport::header('Allow: GET');
                throw new CatalogApiException('METHOD_NOT_ALLOWED', 'Only GET is supported.', 405);
            }
            $rawPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            if (preg_match('/%(?![0-9a-f]{2})/i', $rawPath) === 1) {
                throw new CatalogApiException('INVALID_PATH', 'Invalid path encoding.', 422);
            }
            $path = rawurldecode($rawPath);
            if (!preg_match('#^/api/v1/catalog(?:/(.*))?$#D', $path, $match)) {
                throw new CatalogApiException('NOT_FOUND', 'API route not found.', 404);
            }
            $route = trim($match[1] ?? '', '/');
            $service = $this->service ?? new CatalogV1Service(Db::connection());
            $query = $_GET;
            if (preg_match('#^assets/([0-9]+)$#D', $route, $assetMatch)) {
                CatalogPartsQuery::only($query, []);
                $asset = $service->assetDownload(CatalogPartsQuery::integer($assetMatch[1], 1, 2147483647, 'id'));
                if (isset($asset['redirect'])) {
                    Transport::header('Location: ' . $asset['redirect'], true, 302);
                    return;
                }
                $mime = preg_match('#^[a-z0-9.+_-]+/[a-z0-9.+_-]+$#iD', $asset['mime_type']) === 1 ? $asset['mime_type'] : 'application/octet-stream';
                Transport::header("Content-Security-Policy: sandbox; default-src 'none'");
                $responder->sendFile($asset['file'], $asset['filename'], $mime, $asset['is_download']);
                return;
            }
            if ($route === 'search') {
                $data = $service->search($query);
            } elseif (preg_match('#^series/(.+)$#D', $route, $seriesMatch)) {
                $seriesPath = $seriesMatch[1];
                $subresource = null;
                try {
                    $resource = $service->resolve($seriesPath)['resource'];
                    if ($resource['type'] !== 'series') {
                        throw new CatalogApiException('NOT_FOUND', 'Public series not found.', 404);
                    }
                } catch (CatalogApiException $error) {
                    if ($error->getStatusCode() !== 404 || !preg_match('#^(.+)/(fields|parts|facets)$#D', $seriesPath, $subMatch)) {
                        throw $error;
                    }
                    $seriesPath = $subMatch[1];
                    $subresource = $subMatch[2];
                }
                if ($subresource === 'parts') {
                    $data = $service->parts($seriesPath, $query);
                } elseif ($subresource === 'facets') {
                    $data = $service->facets($seriesPath, $query);
                } else {
                    CatalogPartsQuery::only($query, []);
                    $data = $subresource === 'fields' ? $service->fields($seriesPath) : $service->series($seriesPath);
                }
            } else {
                CatalogPartsQuery::only($query, []);
                $data = match (true) {
                    $route === '' => $service->root(),
                    $route === 'tree' => $service->tree(),
                    str_starts_with($route, 'resolve/') => $service->resolve(substr($route, 8)),
                    str_starts_with($route, 'categories/') => $service->category(substr($route, 11)),
                    str_starts_with($route, 'collections/') => $service->collections(CatalogPartsQuery::text(substr($route, 12), 'collection_key', 191)),
                    default => throw new CatalogApiException('NOT_FOUND', 'API route not found.', 404),
                };
            }
            $responder->sendJson(['success' => true, 'data' => $data]);
        } catch (CatalogApiException $error) {
            $responder->sendError($error->getErrorCode(), $error->getMessage(), $error->getStatusCode(), $error->getDetails());
        } catch (Throwable $error) {
            // Do not disclose SQL, credentials, filesystem paths or exception text to public callers.
            $responder->sendError('INTERNAL_ERROR', 'The public catalog is unavailable. Check database provisioning.', 500);
        }
    }
}
