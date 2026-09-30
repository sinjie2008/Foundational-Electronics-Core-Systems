<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use Closure;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Response;
use CatalogSuite\Services\TypstService;
use Exception;
use InvalidArgumentException;

/**
 * Handles Typst compile, template, preference, and variable API routes.
 */
final class TypstController
{
    /** @var (Closure(): TypstService)|null */
    private ?Closure $typstServiceFactory;

    /**
     * Accept an existing service or a lazy factory for host application bindings.
     *
     * The default preserves construction at each action's existing position,
     * including the legacy construction-before-try behavior.
     *
     * @param (callable(): TypstService)|null $typstServiceFactory
     */
    public function __construct(
        private ?TypstService $typstService = null,
        ?callable $typstServiceFactory = null
    ) {
        $this->typstServiceFactory = $typstServiceFactory === null
            ? null
            : Closure::fromCallable($typstServiceFactory);
    }

    /**
     * Compile Typst source.
     */
    public function compile(): void
    {
        $service = $this->newService();
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            if ($method === 'POST') {
                $input = Request::json();
                $code = (string) ($input['typst'] ?? '');
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                Response::success($service->compileTypst($code, $seriesId));
            } else {
                Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            Response::error('COMPILE_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Handle Typst template collection operations.
     */
    public function templates(): void
    {
        $service = $this->newService();
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            if ($method === 'GET') {
                $id = Request::query('id');
                $seriesId = Request::query('seriesId');
                if ($id) {
                    $data = $service->getTemplate((int) $id);
                } elseif ($seriesId) {
                    $data = $service->listSeriesTemplates((int) $seriesId);
                } else {
                    $data = $service->listGlobalTemplates();
                }
                Response::success($data);
            } elseif ($method === 'POST') {
                $input = Request::json();
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $pdfPath = array_key_exists('lastPdfPath', $input) ? trim((string) $input['lastPdfPath']) : null;
                if ($pdfPath === '') {
                    $pdfPath = null;
                }

                $data = $seriesId
                    ? $service->createSeriesTemplate($seriesId, $title, $description, $code, $pdfPath)
                    : $service->createGlobalTemplate($title, $description, $code, $pdfPath);
                Response::success($data);
            } elseif ($method === 'PUT') {
                $input = Request::json();
                $id = (int) ($input['id'] ?? 0);
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $pdfPath = array_key_exists('lastPdfPath', $input) ? trim((string) $input['lastPdfPath']) : null;
                if ($pdfPath === '') {
                    $pdfPath = null;
                }

                $data = $seriesId
                    ? $service->updateSeriesTemplate($id, $seriesId, $title, $description, $code, $pdfPath)
                    : $service->updateGlobalTemplate($id, $title, $description, $code, $pdfPath);
                Response::success($data);
            } elseif ($method === 'DELETE') {
                $id = Request::query('id');
                if (!$id) {
                    throw new InvalidArgumentException('Missing ID');
                }
                $service->deleteTemplate((int) $id);
                Response::success(null);
            } else {
                Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Handle Typst global and series-scoped variable operations.
     */
    public function variables(): void
    {
        $service = $this->newService();
        $method = $_SERVER['REQUEST_METHOD'];
        $correlationId = Request::correlationId();

        try {
            if ($method === 'GET') {
                $id = Request::query('id');
                $seriesId = (int) (Request::query('seriesId') ?? 0);
                if ($seriesId > 0) {
                    $data = $id ? $service->getScopedVariable((int) $id, $seriesId) : $service->listScopedVariables($seriesId);
                } else {
                    $data = $id ? $service->getGlobalVariable((int) $id) : $service->listGlobalVariables();
                }
                Response::success($data, 200, $correlationId);
            } elseif ($method === 'POST') {
                $isMultipart = (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) || !empty($_FILES);
                if ($isMultipart) {
                    $key = trim((string) ($_POST['key'] ?? ''));
                    $type = (string) ($_POST['type'] ?? 'text');
                    $value = (string) ($_POST['value'] ?? '');
                    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
                    $seriesId = isset($_POST['seriesId']) ? (int) $_POST['seriesId'] : 0;
                    if ($key === '') {
                        Response::error('VALIDATION_ERROR', 'Key is required', 400, $correlationId);
                        return;
                    }
                    if ($seriesId < 0) {
                        Response::error('VALIDATION_ERROR', 'seriesId must be positive when provided.', 400, $correlationId);
                        return;
                    }
                    $fileUpload = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;
                    $data = $seriesId > 0
                        ? $service->saveScopedVariable($seriesId, $key, $type, $value, $id, $fileUpload)
                        : $service->saveGlobalVariable($key, $type, $value, $id, $fileUpload);
                } else {
                    $input = Request::json();
                    $key = trim((string) ($input['key'] ?? ''));
                    $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : 0;
                    if ($key === '') {
                        Response::error('VALIDATION_ERROR', 'Key is required', 400, $correlationId);
                        return;
                    }
                    if ($seriesId < 0) {
                        Response::error('VALIDATION_ERROR', 'seriesId must be positive when provided.', 400, $correlationId);
                        return;
                    }
                    $type = (string) ($input['type'] ?? 'text');
                    $value = (string) ($input['value'] ?? '');
                    $id = isset($input['id']) ? (int) $input['id'] : null;
                    $data = $seriesId > 0
                        ? $service->saveScopedVariable($seriesId, $key, $type, $value, $id)
                        : $service->saveGlobalVariable($key, $type, $value, $id);
                }
                Response::success($data, 200, $correlationId);
            } elseif ($method === 'DELETE') {
                $id = Request::query('id');
                $seriesId = (int) (Request::query('seriesId') ?? 0);
                if (!$id || (int) $id <= 0) {
                    Response::error('VALIDATION_ERROR', 'ID is required', 400, $correlationId);
                    return;
                }
                if ($seriesId > 0) {
                    $service->deleteScopedVariable((int) $id, $seriesId);
                } else {
                    $service->deleteGlobalVariable((int) $id);
                }
                Response::success(null, 200, $correlationId);
            } else {
                Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405, $correlationId);
            }
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500, $correlationId);
        }
    }

    /**
     * Preserve the original Typst template contract at the legacy /api/ path.
     */
    public function legacyTemplates(): void
    {
        $service = $this->newService();
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            if ($method === 'GET') {
                $id = Request::query('id');
                $seriesId = Request::query('seriesId');
                if ($id) {
                    $data = $service->getTemplate((int) $id);
                } elseif ($seriesId) {
                    $data = $service->listSeriesTemplates((int) $seriesId);
                } else {
                    $data = $service->listGlobalTemplates();
                }
                Response::success($data);
            } elseif ($method === 'POST') {
                $input = Request::json();
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $pdfPath = isset($input['lastPdfPath']) ? (string) $input['lastPdfPath'] : null;
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $data = $seriesId
                    ? $service->createSeriesTemplate($seriesId, $title, $description, $code, $pdfPath)
                    : $service->createGlobalTemplate($title, $description, $code, $pdfPath);
                Response::success($data);
            } elseif ($method === 'PUT') {
                $input = Request::json();
                $id = (int) ($input['id'] ?? 0);
                $title = (string) ($input['title'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $code = (string) ($input['typst'] ?? '');
                $pdfPath = isset($input['lastPdfPath']) ? (string) $input['lastPdfPath'] : null;
                $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : null;
                $data = $seriesId
                    ? $service->updateSeriesTemplate($id, $seriesId, $title, $description, $code, $pdfPath)
                    : $service->updateGlobalTemplate($id, $title, $description, $code, $pdfPath);
                Response::success($data);
            } elseif ($method === 'DELETE') {
                // This older endpoint historically had no initialized $id and returns its error envelope.
                throw new InvalidArgumentException('Missing ID');
            } else {
                Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Preserve the original global-only variable contract at the legacy /api/ path.
     */
    public function legacyVariables(): void
    {
        $service = $this->newService();
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            if ($method === 'GET') {
                $id = Request::query('id');
                $data = $id ? $service->getGlobalVariable((int) $id) : $service->listGlobalVariables();
                Response::success($data);
            } elseif ($method === 'POST') {
                $input = Request::json();
                $data = $service->saveGlobalVariable(
                    (string) ($input['key'] ?? ''),
                    (string) ($input['type'] ?? 'text'),
                    (string) ($input['value'] ?? ''),
                    isset($input['id']) ? (int) $input['id'] : null
                );
                Response::success($data);
            } elseif ($method === 'DELETE') {
                $id = Request::query('id');
                if (!$id) {
                    throw new InvalidArgumentException('Missing ID');
                }
                $service->deleteGlobalVariable((int) $id);
                Response::success(null);
            } else {
                Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
            }
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Read and write the remembered global template for a series.
     */
    public function seriesPreferences(): void
    {
        $service = $this->newService();
        $method = $_SERVER['REQUEST_METHOD'];

        try {
            if ($method === 'GET') {
                $seriesId = (int) (Request::query('seriesId') ?? 0);
                if ($seriesId <= 0) {
                    throw new InvalidArgumentException('seriesId is required.');
                }
                Response::success($service->getSeriesPreference($seriesId));
                return;
            }

            if ($method === 'PUT') {
                $input = Request::json();
                $seriesId = (int) ($input['seriesId'] ?? 0);
                if ($seriesId <= 0) {
                    throw new InvalidArgumentException('seriesId is required.');
                }
                $lastGlobalTemplateId = $input['lastGlobalTemplateId'] ?? null;
                if ($lastGlobalTemplateId === '' || $lastGlobalTemplateId === false) {
                    $lastGlobalTemplateId = null;
                }
                if ($lastGlobalTemplateId !== null) {
                    if (!is_numeric($lastGlobalTemplateId)) {
                        throw new InvalidArgumentException('lastGlobalTemplateId must be numeric or null.');
                    }
                    $lastGlobalTemplateId = (int) $lastGlobalTemplateId;
                    if ($lastGlobalTemplateId <= 0) {
                        $lastGlobalTemplateId = null;
                    }
                }

                Response::success($service->saveSeriesPreference($seriesId, $lastGlobalTemplateId));
                return;
            }

            Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 400);
        } catch (Exception $e) {
            Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /** Create or resolve the service only when an endpoint needs it. */
    private function newService(): TypstService
    {
        if ($this->typstService !== null) {
            return $this->typstService;
        }

        if ($this->typstServiceFactory !== null) {
            return ($this->typstServiceFactory)();
        }

        return new TypstService();
    }
}
