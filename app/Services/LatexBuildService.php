<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Support\Config;

use RuntimeException;
use CatalogSuite\Http\CatalogApiException;

final class LatexBuildService
{
    private string $pdflatexPath;

    private string $storageDir;

    private string $workingDir;

    private string $relativePrefix;

    public function __construct(
        string $pdflatexPath,
        ?string $storageDir = null,
        ?string $workingDir = null
    ) {
        $this->pdflatexPath = $pdflatexPath !== '' ? $pdflatexPath : Config::get('app')['latex']['default_binary'];
        $this->storageDir = rtrim($storageDir ?? Config::get('app')['storage']['latex_pdfs'], DIRECTORY_SEPARATOR);
        $this->workingDir = rtrim($workingDir ?? Config::get('app')['storage']['latex_build'], DIRECTORY_SEPARATOR);
        $this->relativePrefix = ltrim(Config::get('app')['latex']['pdf_url_prefix'], '/');

        $this->ensureDirectory($this->storageDir);
        $this->ensureDirectory($this->workingDir);
    }

    /**
     * Compiles LaTeX into a PDF using MiKTeX.
     *
     * @return array<string, mixed>
     */
    public function build(int $templateId, string $latex): array
    {
        $timestamp = (new \DateTimeImmutable('now'))->format('YmdHis');
        $token = bin2hex(random_bytes(4));
        $baseName = sprintf('template_%d_%s_%s', $templateId, $timestamp, $token);
        $texPath = $this->workingDir . DIRECTORY_SEPARATOR . $baseName . '.tex';

        if (file_put_contents($texPath, $latex) === false) {
            throw new CatalogApiException(
                'LATEX_BUILD_ERROR',
                'Unable to write temporary LaTeX source file.',
                500
            );
        }

        $command = sprintf(
            '%s -interaction=nonstopmode -halt-on-error -output-directory %s %s',
            escapeshellarg($this->pdflatexPath),
            escapeshellarg($this->workingDir),
            escapeshellarg($texPath)
        );

        $correlationId = bin2hex(random_bytes(16));
        error_log(
            sprintf(
                '[LatexBuild] action=start templateId=%d correlationId=%s command=%s',
                $templateId,
                $correlationId,
                $command
            )
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->workingDir);
        if (!is_resource($process)) {
            $this->cleanupWorkingFiles($baseName);
            throw new CatalogApiException('LATEX_TOOL_MISSING', 'Unable to execute MiKTeX pdflatex.', 500);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $pdfSource = $this->workingDir . DIRECTORY_SEPARATOR . $baseName . '.pdf';

        try {
            if ($exitCode !== 0 || !is_file($pdfSource)) {
                error_log(
                    sprintf(
                        '[LatexBuild] action=fail templateId=%d correlationId=%s exitCode=%d',
                        $templateId,
                        $correlationId,
                        $exitCode
                    )
                );
                throw new CatalogApiException(
                    'LATEX_BUILD_ERROR',
                    'MiKTeX compilation failed.',
                    500,
                    [
                        'stdout' => trim($stdout),
                        'stderr' => trim($stderr),
                        'exitCode' => $exitCode,
                        'correlationId' => $correlationId,
                    ]
                );
            }

            $pdfFilename = sprintf('%d-%s-%s.pdf', $templateId, $timestamp, $token);
            $targetPath = $this->storageDir . DIRECTORY_SEPARATOR . $pdfFilename;
            $this->movePdf($pdfSource, $targetPath);
            $relativePath = $this->relativePrefix . '/' . $pdfFilename;

            error_log(
                sprintf(
                    '[LatexBuild] action=success templateId=%d correlationId=%s file=%s',
                    $templateId,
                    $correlationId,
                    $targetPath
                )
            );

            return [
                'relativePath' => $relativePath,
                'absolutePath' => $targetPath,
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
                'exitCode' => $exitCode,
                'log' => trim($stdout . PHP_EOL . $stderr),
                'correlationId' => $correlationId,
            ];
        } finally {
            $this->cleanupWorkingFiles($baseName);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create directory: ' . $directory);
            }
        }
    }

    private function cleanupWorkingFiles(string $baseName): void
    {
        $extensions = ['tex', 'aux', 'log', 'out', 'toc', 'pdf'];
        foreach ($extensions as $extension) {
            $path = $this->workingDir . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function movePdf(string $source, string $destination): void
    {
        if (@rename($source, $destination)) {
            return;
        }

        if (!@copy($source, $destination)) {
            throw new CatalogApiException(
                'LATEX_BUILD_ERROR',
                'Unable to move generated PDF into storage.',
                500
            );
        }
        @unlink($source);
    }
}
