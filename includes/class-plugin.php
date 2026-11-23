<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Plugin {

    public const NONCE_ACTION = 'wssf_search_action';
    public const NONCE_FIELD  = 'nonce';
    public const AJAX_ACTION  = 'wssf_search';
    public const SUGGEST_ACTION = 'wssf_suggest';

    private static ?self $instance = null;

    public Assets          $assets;
    public Cache           $cache;
    public Security        $security;
    public CleanTalk       $cleantalk;
    public Query_Parser    $parser;
    public Initial_State   $initial;
    public Search_Engine   $engine;
    public Excerpt         $excerpt;
    public Ajax_Handler    $ajax;
    public Semantic_Search $semantic;
    public Autocomplete    $autocomplete;

    public static function instance(): self {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function get_min_len(): int { return Query_Parser::MIN_LEN; }

    private function __construct() {
        $this->cache     = new Cache();
        $this->security  = new Security( $this->cache );

        $morphology      = new Morphology();
        $tokenizer       = new Tokenizer( $morphology );
        $this->parser    = new Query_Parser( $tokenizer );
        $this->excerpt   = new Excerpt();
        $this->initial   = new Initial_State();

        $this->semantic = new Semantic_Search( $this->cache, $tokenizer );

        $this->engine = new Search_Engine(
            $this->cache,
            $this->excerpt,
            $tokenizer,
            $this->semantic
        );

        $this->autocomplete = new Autocomplete( $this->cache, $tokenizer );

        $this->cleantalk = new CleanTalk( $this->cache, $this->security );
        $corrector       = new Layout_Corrector( $tokenizer );

        $this->ajax = new Ajax_Handler(
            $this->parser,
            $this->engine,
            $this->cleantalk,
            $this->security,
            $corrector,
            $this->autocomplete
        );

        $this->assets = new Assets( $this->initial );

        Hooks::instance( $this )->register();
    }
}