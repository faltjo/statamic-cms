<?php

namespace Tests\Tags;

use Facades\Tests\Factories\EntryFactory;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Tests\PreventSavingStacheItemsToDisk;
use Tests\TestCase;

class MountTagTest extends TestCase
{
    use PreventSavingStacheItemsToDisk;

    /** @test */
    public function it_gets_collection_mount()
    {
        Site::setConfig(['sites' => [
            'english' => ['url' => 'http://localhost/', 'locale' => 'en'],
            'french' => ['url' => 'http://localhost/fr/', 'locale' => 'fr'],
        ]]);

        Collection::make('pages')->sites(['english', 'french'])->routes([
            'english' => 'pages/{slug}',
            'french' => 'le-pages/{slug}',
        ])->save();
        $mountEn = EntryFactory::collection('pages')->slug('blog')->locale('english')->id('blog-en')->create();
        $mountFr = EntryFactory::collection('pages')->slug('le-blog')->locale('french')->origin('blog-en')->id('blog-fr')->create();
        Collection::make('blog')->routes('{mount}/{slug}')->mount($mountEn->id())->save();

        $this->assertParseEquals('/pages/blog', '{{ mount:blog }}');
        $this->assertParseEquals('/pages/blog', '{{ mount handle="blog" }}');

        Site::setCurrent('french');
        $this->assertParseEquals('/fr/le-pages/le-blog', '{{ mount:blog }}');
        $this->assertParseEquals('/fr/le-pages/le-blog', '{{ mount handle="blog" }}');
    }

    private function assertParseEquals($expected, $template, $context = [])
    {
        $this->assertEquals($expected, (string) Antlers::parse($template, $context));
    }
}
