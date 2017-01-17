<?php namespace Sereno\Extensions;

use Sereno\AbstractExtension;

class DocsExtension extends AbstractExtension
{
    public function provide()
    {
        $this->registerConfig('docs', require __DIR__.'/config.php');
    }

    public function getBuilders(): array
    {
        return [
            Docs\DocsBuilder::class,
        ];
    }

    public function getViewsDirectory(): array
    {
        return [dirname(__DIR__).'/resources/views'];
    }

    public function getContentDirectory(): array
    {
        return (array) config('docs.directory');
    }
}
