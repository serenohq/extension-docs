<?php namespace Sereno\Extensions\Docs;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Sereno\Contracts\Builder;
use Sereno\DataExtractor;
use Sereno\Parsers\Markdown;
use Sereno\ProcessorFactory;
use Symfony\Component\Finder\SplFileInfo;

class DocsBuilder implements Builder
{
    use \Sereno\Traits\ViewFinderTrait;
    /**
     * File processor generates HTML from source.
     *
     * @var \Sereno\ProcessorFactory
     */
    protected $processor;

    /**
     * Data Extractor parses front data from a file.
     *
     * @var \Sereno\DataExtractor
     */
    protected $extractor;

    /**
     * Filesystem instance.
     *
     * @var \Illuminate\Filesystems\Filesystem
     */
    protected $filesystem;

    /**
     * Blade view factory.
     *
     * @var \Illuminate\View\Factory
     */
    protected $viewFactory;

    /**
     * The directory containing docs.
     *
     * @var string
     */
    protected $docsDirectory;

    /**
     * The filename to choose as index.
     *
     * @var string
     */
    protected $indexFilename;

    /**
     * The base URL for docs.
     *
     * @var string
     */
    protected $baseURL;

    public function __construct(ProcessorFactory $processor, DataExtractor $extractor, Filesystem $filesystem, Factory $viewFactory)
    {
        $this->processor = $processor;
        $this->extractor = $extractor;
        $this->filesystem = $filesystem;
        $this->viewFactory = $viewFactory;

        $this->docsDirectory = config('docs.directory');
        $this->indexFilename = config('docs.index');
        $this->baseURL = config('docs.url_prefix');
    }

    public function handledPatterns(): array
    {
        return [$this->docsDirectory.'/*'];
    }

    public function data(array $files, array $data): array
    {
        return $data;
    }

    public function build(array $files, array $data)
    {
        list($index, $docs) = $this->filterFiles($files);

        if (!count($docs)) return;

        $options = [
            'view' => [
                'extends' => config('docs.extends'),
                'yields'  => config('docs.yields'),
            ],
            'interceptor' => [$this, 'getOutputFilename'],
        ];
        $fallback_docs_index = $this->compileWithBlade($index, $data);

        $landings = $this->getLandings($docs);

        foreach ($docs as $doc) {
            $docs_index = $this->getCompiledDocIndex($doc, $fallback_docs_index, $data);
            $this->processor->process($doc, $data + compact('docs_index'), $options);
        }

        foreach ($landings as $path => $target) {

            $doc = new SplFileInfo(
                $this->docsDirectory.DIRECTORY_SEPARATOR.$target,
                $target,
                $this->docsDirectory.DIRECTORY_SEPARATOR.$target);

            $indexOptions = ['interceptor' => function () use ($path) {
                    $index = $this->baseURL.DIRECTORY_SEPARATOR.$path.DIRECTORY_SEPARATOR.'index.html';
                    return trim($index, DIRECTORY_SEPARATOR);
                }] + $options;

            if (config('docs.redirect')) {
                $filename = substr($target, 0, -strlen('.md'));
                $data['target'] = url(trim(
                    $this->baseURL.DIRECTORY_SEPARATOR.$filename, DIRECTORY_SEPARATOR
                ));
                $doc = new SplFileInfo($this->viewFactory->getFinder()->find('redirector'), '', '');
            }

            $this->processor->process($doc, compact('docs_index') + $data, $indexOptions);
        }
    }

    protected function getLandings(array $docs)
    {
        $landings = [];
        $paths = [];

        $default = array_first(explode('.', array_first($docs)->getBasename(), 2));
        $config = config('docs.default', $default);
        $indexes = collect(explode(',', $config));

        foreach ($docs as $doc) {
            $relativePath = $doc->getRelativePath();
            if (!in_array($relativePath, $paths)) {
                $paths[] = $doc->getRelativePath();
            }

            $name = substr($doc->getBasename(), 0, -strlen('.md'));

            if (in_array($name, $indexes->all())) {
                $landings[$doc->getRelativePath()] = $doc->getRelativePathname();
            }
        }

        $keys = array_keys($landings);

        $filtered = collect($paths)->filter(function ($path) use ($keys) {
            return !in_array($path, $keys);
        })->unique()->all();

        foreach ($filtered as $path) {
            if ($landing = $this->findLanding($indexes, $path, $landings)) {
                $landings[$path] = $landing;
            }
        }

        return $landings;
    }

    protected function findLanding($indexes, $path, $paths)
    {
        if (isset($paths[$path])) {
            return $paths[$path];
        }

        foreach ($indexes as $index) {
            if (isset($paths[$path.'/'.$index])) {
                return $paths[$path.'/'.$index];
            }
        }
    }

    protected function getRedirectFile()
    {
        return $this->getView('redirect');
    }

    protected function compileWithBlade(string $content, array $data): string
    {
        $viewCache = cache_dir(sha1($this->indexFilename).'.php');
        $this->filesystem->put($viewCache, $this->getCompiler()->compileString($content));
        $content = (new PhpEngine())->get($viewCache, $this->getViewData(['docs_url' => rtrim(url($this->baseURL), '/')] + $data));

        return Markdown::parse($content);
    }

    protected function getCompiledDocIndex(SplFileInfo $doc, string $fallback, array $data): string
    {
        $path = realpath($this->docsDirectory.DIRECTORY_SEPARATOR.$doc->getRelativePath());
        $file = config('docs.index').'.md';

        if (! $this->filesystem->exists($path.'/'.$file)) {
            return $fallback;
        }

        return $this->compileWithBlade(
            $this->filesystem->get($path.'/'.$file),
            $data
        );
    }

    public function getOutputFilename(SplFileInfo $file): string
    {
        $filename = $file->getFilename();
        $extension = last(explode('.', $filename, 2));
        $basename = preg_replace('/\.'.preg_quote($extension).'$/', '', $filename);
        $directory = preg_replace('#^'.preg_quote($this->docsDirectory, '#').'#', '', $file->getRelativePath());
        $baseURL = str_replace('/', DIRECTORY_SEPARATOR, $this->baseURL);
        $directory = trim(trim($baseURL, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.trim($directory, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);

        if (hash_equals('', $directory)) {
            return $basename.DIRECTORY_SEPARATOR.'index.html';
        }

        return $directory.DIRECTORY_SEPARATOR.$basename.DIRECTORY_SEPARATOR.'index.html';
    }

    protected function getOutputUrl(SplFileInfo $file): string
    {
        return str_replace('\\', '/', dirname($this->getOutputFilename($file)));
    }

    protected function filterFiles(array $files): array
    {
        $index = null;
        $docs = array_filter($files, function (SplFileInfo $file) use (&$index) {
            if (starts_with($file->getBasename(), $this->indexFilename)) {
                $index = $file;

                return false;
            }

            return true;
        });

        if (is_null($index)) {
            $index = $this->buildIndex($docs);
        } else {
            $index = $index->getContents();
        }

        return [$index, $docs];
    }

    protected function buildIndex(array $files): string
    {
        $items = array_map(function (SplFileInfo $file) {
            $url = $this->getOutputUrl($file);
            $title = ucfirst(str_replace('-', ' ', last(explode('/', $url))));

            return compact('url', 'title');
        }, $files);

        return array_reduce($items, function (string $content, array $item) {
            return $content .= "- [{$item['title']}]({$item['url']})\n";
        }, '');
    }

    protected function getViewData($data)
    {
        $data = array_merge($this->viewFactory->getShared(), $data);

        foreach ($data as $key => $value) {
            if ($value instanceof Renderable) {
                $data[$key] = $value->render();
            }
        }

        return $data;
    }

    protected function getCompiler(): BladeCompiler
    {
        /** @var Blade $blade */
        $blade = $this->viewFactory->getEngineResolver()->resolve('blade');

        return $blade->getCompiler();
    }
}
