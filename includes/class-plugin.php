<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Plugin {

    public const NONCE_ACTION = 'wssf_search_action';
    public const NONCE_FIELD  = 'nonce';
    public const AJAX_ACTION  = 'wssf_search';
    public const SUGGEST_ACTION = 'wssf_suggest';

    private static ?self $instance = null;

    public WSSF_Assets          $assets;
    public WSSF_Cache           $cache;
    public WSSF_Security        $security;
    public WSSF_CleanTalk       $cleantalk;
    public WSSF_Query_Parser    $parser;
    public WSSF_Initial_State   $initial;
    public WSSF_Search_Engine   $engine;
    public WSSF_Excerpt         $excerpt;
    public WSSF_Ajax_Handler    $ajax;
    public WSSF_Semantic_Search $semantic;
    public WSSF_Autocomplete    $autocomplete;

    public static function instance(): self {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function get_min_len(): int { return WSSF_Query_Parser::MIN_LEN; }

    private function __construct() {
        $this->cache     = new WSSF_Cache();
        $this->security  = new WSSF_Security( $this->cache );

        $morphology      = new WSSF_Morphology();
        $tokenizer       = new WSSF_Tokenizer( $morphology );
        $this->parser    = new WSSF_Query_Parser( $tokenizer );
        $this->excerpt   = new WSSF_Excerpt();
        $this->initial   = new WSSF_Initial_State();

        $this->semantic = new WSSF_Semantic_Search( $this->cache, $tokenizer );

        $this->engine = new WSSF_Search_Engine(
            $this->cache,
            $this->excerpt,
            $tokenizer,
            $this->semantic
        );

        $this->autocomplete = new WSSF_Autocomplete( $this->cache, $tokenizer );

        $this->cleantalk = new WSSF_CleanTalk( $this->cache, $this->security );
        $corrector       = new WSSF_Layout_Corrector( $tokenizer );

        $this->ajax = new WSSF_Ajax_Handler(
            $this->parser,
            $this->engine,
            $this->cleantalk,
            $this->security,
            $corrector,
            $this->autocomplete
        );

        $this->assets = new WSSF_Assets( $this->initial );

        WSSF_Hooks::instance( $this )->register();
    }
}