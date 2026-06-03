<?php
/**
 * Crawler detector class
 *
 * Detects AI crawlers by analyzing User-Agent strings.
 * Contains an extensive list of known AI crawler signatures.
 *
 * @package VigIA
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Crawler detector class
 */
class VigIA_Crawler_Detector {

    /**
     * List of known AI crawlers with their categories
     * Categories: training (model training), search (AI search), assistant (chat/assistant), scraper (data collection)
     *
     * @var array
     */
    private static $crawlers = array(
        // OpenAI
        'GPTBot'              => array(
            'name'     => 'GPTBot',
            'company'  => 'OpenAI',
            'category' => 'training',
        ),
        'OAI-SearchBot'       => array(
            'name'     => 'OAI-SearchBot',
            'company'  => 'OpenAI',
            'category' => 'search',
        ),
        'ChatGPT-User'        => array(
            'name'     => 'ChatGPT-User',
            'company'  => 'OpenAI',
            'category' => 'assistant',
        ),
        'ChatGPT Agent'       => array(
            'name'     => 'ChatGPT Agent',
            'company'  => 'OpenAI',
            'category' => 'assistant',
        ),

        // Anthropic (Claude)
        'ClaudeBot'           => array(
            'name'     => 'ClaudeBot',
            'company'  => 'Anthropic',
            'category' => 'training',
        ),
        'Claude-Web'          => array(
            'name'     => 'Claude-Web',
            'company'  => 'Anthropic',
            'category' => 'assistant',
        ),
        'Claude-SearchBot'    => array(
            'name'     => 'Claude-SearchBot',
            'company'  => 'Anthropic',
            'category' => 'search',
        ),
        'Claude-User'         => array(
            'name'     => 'Claude-User',
            'company'  => 'Anthropic',
            'category' => 'assistant',
        ),
        'anthropic-ai'        => array(
            'name'     => 'Anthropic-AI',
            'company'  => 'Anthropic',
            'category' => 'training',
        ),

        // Google
        'Google-Extended'     => array(
            'name'     => 'Google-Extended',
            'company'  => 'Google',
            'category' => 'training',
        ),
        'Google-CloudVertexBot' => array(
            'name'     => 'Google-CloudVertexBot',
            'company'  => 'Google',
            'category' => 'training',
        ),
        'Gemini-Deep-Research' => array(
            'name'     => 'Gemini-Deep-Research',
            'company'  => 'Google',
            'category' => 'search',
        ),
        'GoogleOther'         => array(
            'name'     => 'GoogleOther',
            'company'  => 'Google',
            'category' => 'scraper',
        ),
        'Google-Agent'        => array(
            'name'     => 'Google-Agent',
            'company'  => 'Google',
            'category' => 'assistant',
        ),

        // Perplexity
        'PerplexityBot'       => array(
            'name'     => 'PerplexityBot',
            'company'  => 'Perplexity',
            'category' => 'search',
        ),
        'Perplexity-User'     => array(
            'name'     => 'Perplexity-User',
            'company'  => 'Perplexity',
            'category' => 'assistant',
        ),

        // ByteDance / TikTok
        'Bytespider'          => array(
            'name'     => 'Bytespider',
            'company'  => 'ByteDance',
            'category' => 'training',
        ),
        'TikTokSpider'        => array(
            'name'     => 'TikTokSpider',
            'company'  => 'ByteDance',
            'category' => 'scraper',
        ),

        // Meta / Facebook
        'FacebookBot'         => array(
            'name'     => 'FacebookBot',
            'company'  => 'Meta',
            'category' => 'scraper',
        ),
        'Meta-ExternalAgent'  => array(
            'name'     => 'Meta-ExternalAgent',
            'company'  => 'Meta',
            'category' => 'training',
        ),
        'Meta-ExternalFetcher' => array(
            'name'     => 'Meta-ExternalFetcher',
            'company'  => 'Meta',
            'category' => 'scraper',
        ),
        'meta-webindexer'     => array(
            'name'     => 'Meta-WebIndexer',
            'company'  => 'Meta',
            'category' => 'scraper',
        ),

        // Amazon
        'Amazonbot'           => array(
            'name'     => 'Amazonbot',
            'company'  => 'Amazon',
            'category' => 'training',
        ),

        // Apple
        'Applebot-Extended'   => array(
            'name'     => 'Applebot-Extended',
            'company'  => 'Apple',
            'category' => 'training',
        ),
        'Applebot'            => array(
            'name'     => 'Applebot',
            'company'  => 'Apple',
            'category' => 'search',
        ),

        // Huawei
        'PetalBot'            => array(
            'name'     => 'PetalBot',
            'company'  => 'Huawei',
            'category' => 'search',
        ),

        // Microsoft / Bing
        'bingbot'             => array(
            'name'     => 'BingBot',
            'company'  => 'Microsoft',
            'category' => 'search',
        ),

        // Common Crawl
        'CCBot'               => array(
            'name'     => 'CCBot',
            'company'  => 'Common Crawl',
            'category' => 'training',
        ),

        // Cohere
        'cohere-ai'           => array(
            'name'     => 'Cohere-AI',
            'company'  => 'Cohere',
            'category' => 'training',
        ),
        'cohere-training-data-crawler' => array(
            'name'     => 'Cohere Training Crawler',
            'company'  => 'Cohere',
            'category' => 'training',
        ),

        // DeepSeek
        'DeepSeekBot'         => array(
            'name'     => 'DeepSeekBot',
            'company'  => 'DeepSeek',
            'category' => 'training',
        ),

        // xAI (Elon Musk)
        'xAI-Bot'             => array(
            'name'     => 'xAI-Bot',
            'company'  => 'xAI',
            'category' => 'training',
        ),

        // Diffbot
        'Diffbot'             => array(
            'name'     => 'Diffbot',
            'company'  => 'Diffbot',
            'category' => 'scraper',
        ),

        // DuckDuckGo
        'DuckAssistBot'       => array(
            'name'     => 'DuckAssistBot',
            'company'  => 'DuckDuckGo',
            'category' => 'assistant',
        ),

        // AI2 (Allen Institute)
        'AI2Bot'              => array(
            'name'     => 'AI2Bot',
            'company'  => 'Allen Institute',
            'category' => 'training',
        ),
        'Ai2Bot-Dolma'        => array(
            'name'     => 'AI2Bot-Dolma',
            'company'  => 'Allen Institute',
            'category' => 'training',
        ),

        // You.com
        'YouBot'              => array(
            'name'     => 'YouBot',
            'company'  => 'You.com',
            'category' => 'search',
        ),

        // Hugging Face
        'HuggingFace-Bot'     => array(
            'name'     => 'HuggingFace-Bot',
            'company'  => 'Hugging Face',
            'category' => 'training',
        ),

        // Mistral
        'MistralAI-User'      => array(
            'name'     => 'MistralAI-User',
            'company'  => 'Mistral AI',
            'category' => 'assistant',
        ),

        // Firecrawl
        'FirecrawlAgent'      => array(
            'name'     => 'FirecrawlAgent',
            'company'  => 'Firecrawl',
            'category' => 'scraper',
        ),

        // Timpi
        'TimpiBot'            => array(
            'name'     => 'TimpiBot',
            'company'  => 'Timpi',
            'category' => 'scraper',
        ),

        // Webzio
        'Webzio-Extended'     => array(
            'name'     => 'Webzio-Extended',
            'company'  => 'Webzio',
            'category' => 'scraper',
        ),

        // PanguBot (Alibaba)
        'PanguBot'            => array(
            'name'     => 'PanguBot',
            'company'  => 'Alibaba',
            'category' => 'training',
        ),

        // Kangaroo Bot
        'Kangaroo Bot'        => array(
            'name'     => 'Kangaroo Bot',
            'company'  => 'Unknown',
            'category' => 'scraper',
        ),

        // Imagesift
        'ImagesiftBot'        => array(
            'name'     => 'ImagesiftBot',
            'company'  => 'Imagesift',
            'category' => 'scraper',
        ),

        // Omgili
        'Omgilibot'           => array(
            'name'     => 'Omgilibot',
            'company'  => 'Omgili',
            'category' => 'scraper',
        ),
        'Omgili'              => array(
            'name'     => 'Omgili',
            'company'  => 'Omgili',
            'category' => 'scraper',
        ),

        // Groq
        'Groq-Bot'            => array(
            'name'     => 'Groq-Bot',
            'company'  => 'Groq',
            'category' => 'assistant',
        ),

        // Devin (Cognition AI)
        'Devin'               => array(
            'name'     => 'Devin',
            'company'  => 'Cognition AI',
            'category' => 'assistant',
        ),

        // FriendlyCrawler
        'FriendlyCrawler'     => array(
            'name'     => 'FriendlyCrawler',
            'company'  => 'Unknown',
            'category' => 'scraper',
        ),

        // img2dataset
        'img2dataset'         => array(
            'name'     => 'img2dataset',
            'company'  => 'LAION',
            'category' => 'training',
        ),

        // ICC-Crawler
        'ICC-Crawler'         => array(
            'name'     => 'ICC-Crawler',
            'company'  => 'Unknown',
            'category' => 'scraper',
        ),

        // Crawlspace
        'Crawlspace'          => array(
            'name'     => 'Crawlspace',
            'company'  => 'Unknown',
            'category' => 'scraper',
        ),

        // iaskspider
        'iaskspider'          => array(
            'name'     => 'iAskSpider',
            'company'  => 'iAsk.AI',
            'category' => 'search',
        ),

        // Together AI
        'Together-Bot'        => array(
            'name'     => 'Together-Bot',
            'company'  => 'Together AI',
            'category' => 'training',
        ),

        // Replicate
        'Replicate-Bot'       => array(
            'name'     => 'Replicate-Bot',
            'company'  => 'Replicate',
            'category' => 'training',
        ),

        // Cloudflare
        'Cloudflare-AutoRAG'  => array(
            'name'     => 'Cloudflare-AutoRAG',
            'company'  => 'Cloudflare',
            'category' => 'scraper',
        ),
    );

    /**
     * Detect if current request is from an AI crawler
     *
     * @param string $user_agent Optional user agent string. Uses $_SERVER if not provided.
     * @return array|false Crawler info array or false if not detected.
     */
    public static function detect( $user_agent = null ) {
        if ( null === $user_agent ) {
            $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        }

        if ( empty( $user_agent ) ) {
            return false;
        }

        // First check custom crawlers (they take priority)
        $custom_crawlers = self::get_custom_crawlers();
        foreach ( $custom_crawlers as $id => $crawler ) {
            if ( false !== stripos( $user_agent, $crawler['user_agent'] ) ) {
                return array(
                    'pattern'  => $crawler['user_agent'],
                    'name'     => $crawler['name'],
                    'company'  => $crawler['company'],
                    'category' => $crawler['category'],
                );
            }
        }

        // Then check built-in crawlers
        foreach ( self::$crawlers as $pattern => $info ) {
            if ( false !== stripos( $user_agent, $pattern ) ) {
                return array(
                    'pattern'  => $pattern,
                    'name'     => $info['name'],
                    'company'  => $info['company'],
                    'category' => $info['category'],
                );
            }
        }

        return false;
    }

    /**
     * Whether the current request has already been logged.
     *
     * Guards against logging the same hit twice when more than one code path
     * can record it (for example a markdown endpoint that serves its response
     * and exits before the shutdown tracker runs).
     *
     * @var bool
     */
    private static $logged_this_request = false;

    /**
     * Track current request if it's from an AI crawler
     */
    public static function track_request() {
        if ( self::$logged_this_request ) {
            return;
        }

        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $crawler    = self::detect( $user_agent );

        if ( ! $crawler ) {
            return;
        }

        // Get request URL and path
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $request_url = home_url( $request_uri );

        // Get client IP
        $ip_address = self::get_client_ip();

        // Prepare data
        $data = array(
            'crawler_name'     => $crawler['name'],
            'crawler_category' => $crawler['category'],
            'user_agent'       => $user_agent,
            'request_url'      => $request_url,
            'request_path'     => wp_parse_url( $request_uri, PHP_URL_PATH ),
            'ip_address'       => $ip_address,
            'http_status'      => http_response_code() ? http_response_code() : 200,
            'visit_date'       => current_time( 'mysql' ),
        );

        // Insert into database. Mark as logged first so any later code path in
        // the same request (such as the shutdown tracker firing after a
        // markdown endpoint has exited) does not record this hit again.
        self::$logged_this_request = true;
        VigIA_Database::insert_visit( $data );
    }

    /**
     * Mark the current request as already logged.
     *
     * Called by alternate code paths that record their own visit (such as the
     * markdown endpoints) so the shutdown tracker does not log it a second time.
     */
    public static function mark_logged() {
        self::$logged_this_request = true;
    }

    /**
     * Get client IP address
     *
     * @return string IP address.
     */
    private static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // Handle comma-separated IPs (X-Forwarded-For)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get custom crawlers from settings
     *
     * @return array Custom crawlers.
     */
    public static function get_custom_crawlers() {
        if ( class_exists( 'VigIA_Settings' ) ) {
            return VigIA_Settings::get_custom_crawlers();
        }
        return array();
    }

    /**
     * Get list of all known crawlers (built-in only)
     *
     * @return array List of crawlers with info.
     */
    public static function get_crawler_list() {
        return self::$crawlers;
    }

    /**
     * Get all crawlers including custom ones
     *
     * @return array All crawlers.
     */
    public static function get_all_crawlers() {
        $all = self::$crawlers;
        $custom = self::get_custom_crawlers();
        
        foreach ( $custom as $id => $crawler ) {
            $all[ $crawler['user_agent'] ] = array(
                'name'     => $crawler['name'],
                'company'  => $crawler['company'],
                'category' => $crawler['category'],
                'custom'   => true,
            );
        }
        
        return $all;
    }

    /**
     * Get category labels for display
     *
     * @return array Category labels.
     */
    public static function get_category_labels() {
        return array(
            'training'  => __( 'AI Training', 'vigia' ),
            'search'    => __( 'AI Search', 'vigia' ),
            'assistant' => __( 'AI Assistant', 'vigia' ),
            'scraper'   => __( 'Data Scraper', 'vigia' ),
            'unknown'   => __( 'Unknown', 'vigia' ),
        );
    }

    /**
     * Get category colors for charts
     *
     * @return array Category colors.
     */
    public static function get_category_colors() {
        return array(
            'training'  => '#e74c3c',
            'search'    => '#3498db',
            'assistant' => '#2ecc71',
            'scraper'   => '#f39c12',
            'unknown'   => '#95a5a6',
        );
    }
}