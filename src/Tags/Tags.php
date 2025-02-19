<?php

namespace Statamic\Tags;

use Illuminate\Support\Traits\Macroable;
use Statamic\Contracts\View\TagRenderer;
use Statamic\Extend\HasAliases;
use Statamic\Extend\HasHandle;
use Statamic\Extend\RegistersItself;
use Statamic\Facades\Antlers;
use Statamic\Support\Arr;
use Statamic\Support\Traits\Hookable;

abstract class Tags
{
    use HasAliases, HasHandle, Hookable, Macroable, RegistersItself;

    protected static $binding = 'tags';

    /**
     * The content written between the tags (when a tag pair).
     *
     * @public string
     */
    public $content;

    /**
     * The variable context around which this tag is positioned.
     *
     * @public array
     */
    public $context;

    /**
     * The parameters used on this tag.
     *
     * @public array
     */
    public $params;

    /**
     * The tag that was used.
     *
     * eg. For {{ ron:swanson foo="bar" }}, this would be `ron:swanson`
     *     and for {{ ron foo="bar" }} it would be `ron:index`
     *
     * @var string
     */
    public $tag;

    /**
     * The tag method that was used.
     *
     * eg. For {{ ron:swanson foo="bar" }}, this would be `swanson`
     *     and for {{ ron foo="bar" }}, it would `index`
     *
     * @var string
     */
    public $method;

    /**
     * If is a tag pair.
     *
     * @var bool
     */
    public $isPair;

    /**
     * The parser instance that executed this tag.
     *
     * @var \Statamic\View\Antlers\Parser
     */
    public $parser;

    /**
     * The method that will handle wildcard tags.
     *
     * @var string
     */
    protected $wildcardMethod = 'wildcard';

    /**
     * Whether a wildcard method has already been handled.
     *
     * @var bool
     */
    protected $wildcardHandled;

    /**
     * A custom tag renderer that may be used when no Antlers parser is available.
     *
     * @var TagRenderer|null
     */
    protected $tagRenderer;

    public function setProperties($properties)
    {
        $this->setParser($properties['parser']);
        $this->setContent($properties['content']);
        $this->setContext($properties['context']);
        $this->setParameters($properties['params']);
        $this->tag = Arr::get($properties, 'tag');
        $this->method = Arr::get($properties, 'tag_method');

        $this->runHooks('init');
    }

    public function setParser($parser)
    {
        $this->parser = $parser;

        return $this;
    }

    public function setContent($content)
    {
        $this->content = $content;
        $this->isPair = $content !== '';

        return $this;
    }

    public function setContext($context)
    {
        $this->context = new Context($context);

        return $this;
    }

    public function setParameters($parameters)
    {
        $this->params = Parameters::make($parameters, $this->context);

        return $this;
    }

    public function setTagRenderer($tagRenderer)
    {
        $this->tagRenderer = $tagRenderer;

        return $this;
    }

    protected function templatingLanguage()
    {
        if ($this->tagRenderer) {
            return $this->tagRenderer->getLanguage();
        }

        return 'antlers';
    }

    protected function isAntlersBladeComponent()
    {
        return $this->templatingLanguage() === 'blade';
    }

    protected function canParseContents()
    {
        return $this->parser != null || $this->tagRenderer != null;
    }

    /**
     * Handle missing methods.
     *
     * If classes want to provide a catch-all tag, they should add a `wildcard` method.
     */
    public function __call($method, $args)
    {
        if ($this->wildcardHandled || ! method_exists($this, $this->wildcardMethod)) {
            throw new \BadMethodCallException("Call to undefined method {$method}.");
        }

        if (static::hasMacro($method)) {
            $macro = static::$macros[$method];

            if ($macro instanceof \Closure) {
                $macro = $macro->bindTo($this, static::class);
            }

            return $macro(...$args);
        }

        $this->wildcardHandled = true;

        return $this->{$this->wildcardMethod}($this->method);
    }

    /**
     * Parse the tag pair contents.
     *
     * @param  array  $data  Data to be parsed into template
     * @return string
     */
    public function parse($data = [])
    {
        if ($scope = $this->params->get('scope')) {
            $data = Arr::addScope($data, $scope);
        }

        if (! $this->parser) {
            if ($this->tagRenderer) {
                return $this->tagRenderer->render($this->content, array_merge($this->context->all(), $data));
            }

            return $data;
        }

        return Antlers::usingParser($this->parser, function ($antlers) use ($data) {
            return $antlers
                ->parse($this->content, array_merge($this->context->all(), $data))
                ->withoutExtractions();
        });
    }

    protected function aliasedResult($data)
    {
        if ($as = $this->params->get('as')) {
            return [$as => $data];
        }

        return $data;
    }

    /**
     * Iterate over the data and parse the tag pair contents for each.
     *
     * @param  array|\Statamic\Data\DataCollection  $data  Data to iterate over
     * @param  bool  $supplement  Whether to supplement with contextual values
     * @return string
     */
    public function parseLoop($data, $supplement = true)
    {
        if ($as = $this->params->get('as')) {
            return $this->parse([$as => $data]);
        }

        if ($scope = $this->params->get('scope')) {
            $data = Arr::addScope($data, $scope);
        }

        if (! $this->parser) {
            return $data;
        }

        return Antlers::usingParser($this->parser, function ($antlers) use ($data, $supplement) {
            return $antlers
                ->parseLoop($this->content, $data, $supplement, $this->context->all())
                ->withoutExtractions();
        });
    }

    /**
     * Parse with no results.
     *
     * @param  array  $data  Extra data to merge
     * @return string
     */
    public function parseNoResults($data = [])
    {
        $data = array_merge($data, [
            'no_results' => true,
            'total_results' => 0,
        ]);

        return $this->parser ? $this->parse($data) : $data;
    }
}
