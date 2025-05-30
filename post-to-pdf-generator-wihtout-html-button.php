<?php
/**
 * Plugin Name: Post to PDF Generator
 * Description: Adds a "Download PDF" button to posts and generates PDFs using Dompdf.
 * Version: 1.1
 * Author: Muhammad Elias
 * Author URI: https://buildwithelias.tech
 */

defined('ABSPATH') || exit;

use Dompdf\Dompdf;
use Dompdf\Options;

// Load Dompdf
add_action('init', function () {
    if (!class_exists('Dompdf\Dompdf')) {
        require_once plugin_dir_path(__FILE__) . 'libs/dompdf/autoload.inc.php';
    }
});

// Extract largest image from srcset
function extract_largest_from_srcset($srcset) {
    $items = explode(',', $srcset);
    $largest = '';
    $max_width = 0;

    foreach ($items as $item) {
        $parts = preg_split('/\s+/', trim($item));
        if (count($parts) >= 2) {
            $url = $parts[0];
            $width = (int) filter_var($parts[1], FILTER_SANITIZE_NUMBER_INT);
            if ($width > $max_width) {
                $max_width = $width;
                $largest = $url;
            }
        }
    }

    return $largest;
}

// Sanitize and fix <img> tags
function sanitize_img_tags_for_pdf($content) {
    return preg_replace_callback('/<img[^>]*>/i', function ($matches) {
        $img = $matches[0];

        // Attempt to find highest-res image from srcset or data-srcset
        $highest_src = '';

        if (preg_match('/data-srcset=["\']([^"\']+)["\']/', $img, $set)) {
            $highest_src = extract_largest_from_srcset($set[1]);
        } elseif (preg_match('/srcset=["\']([^"\']+)["\']/', $img, $set)) {
            $highest_src = extract_largest_from_srcset($set[1]);
        } elseif (preg_match('/data-src=["\']([^"\']+)["\']/', $img, $match)) {
            $highest_src = $match[1];
        } elseif (preg_match('/src=["\']([^"\']+)["\']/', $img, $match)) {
            $highest_src = $match[1];
        }

        if (!empty($highest_src)) {
            if (preg_match('/src=["\'][^"\']*["\']/', $img)) {
                $img = preg_replace('/src=["\'][^"\']*["\']/', 'src="' . esc_url($highest_src) . '"', $img);
            } else {
                $img = preg_replace('/<img/', '<img src="' . esc_url($highest_src) . '"', $img);
            }
        }

        $img = preg_replace('/class=["\'][^"\']*lazy[^"\']*["\']/', '', $img);
        $img = preg_replace('/style=["\'][^"\']*["\']/', '', $img);

        return '<span style="display:inline;">' . $img . '</span>';
    }, $content);
}

// PDF generation logic
add_action('template_redirect', function () {
    if (!is_singular('post')) return;

    global $post;
    setup_postdata($post);

    $html = '<h1>' . get_the_title() . '</h1>';

    if (has_post_thumbnail()) {
        $img_url = get_the_post_thumbnail_url($post, 'large');
        $html .= '<img src="' . esc_url($img_url) . '" />';
    }

    $content_raw = get_post_field('post_content', $post->ID);
    $content_parsed = do_shortcode($content_raw);
    $post_content = apply_filters('the_content', $content_parsed);

    // Clean and sanitize
    $post_content = str_replace(['“', '”', '‘', '’', '�'], ['"', '"', "'", "'", ''], $post_content);
    $post_content = preg_replace('/\[(\/?vc_[^\]]+)\]/i', '', $post_content);
    $post_content = preg_replace('/\[[^\]]*?\]/i', '', $post_content);
    $post_content = sanitize_img_tags_for_pdf($post_content);
    $post_content = preg_replace('/<p>(\s|&nbsp;)*<\/p>/i', '', $post_content);
    $post_content = preg_replace('/<p>\s*(<img[^>]+>)\s*<\/p>/i', '$1', $post_content);

    $html .= $post_content;

    // CSS
    $css = '
        <style>
            body {
                font-family: Raleway, serif;
                font-size: 15px;
                line-height: 1.4;
            }
            h1 {
                font-size: 24px;
                margin-bottom: 20px;
                line-height: 1.3;
            }
            h2 {
                font-size: 16px;
                font-weight: 700;
                font-family: Raleway, serif;
                line-height: 1.3;
            }
            img {
                max-width: 708px;
                width: 100%;
                height: auto;
                margin-bottom: 0;
                display: inline;
            }
            p {
                margin-bottom: 5px;
            }
        </style>
    ';

    // PDF render
    if (isset($_GET['download_pdf'])) {
        header('X-Robots-Tag: noindex, nofollow', true);
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($css . $html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream(sanitize_title(get_the_title()) . '.pdf', ['Attachment' => false]);
        exit;
    }
});

// Add PDF button only (no preview)
add_filter('the_content', function ($content) {
    if (is_singular('post') && in_the_loop() && is_main_query()) {
        $download_link = add_query_arg('download_pdf', '1', get_permalink());

        $style = 'display: inline-block;
            background-color: #000000;
            color: #fff;
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 400;
            font-size: 13px;
            font-family: Raleway, serif;
            margin-right: 10px;';

        $buttons = '<div class="download-pdf-wrap" style="margin-top: 30px;">';
        $buttons .= '<a id="download-pdf-btn" href="' . esc_url($download_link) . '" target="_blank" rel="nofollow" style="' . $style . '">📄 View PDF</a>';
        $buttons .= '</div>';

        return $content . $buttons;
    }
    return $content;
});
