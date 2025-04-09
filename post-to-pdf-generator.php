<?php
/**
 * Plugin Name: Post to PDF Generator
 * Description: Adds a "Download PDF" button to posts and generates PDFs using Dompdf.
 * Version: 1.5
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

// Sanitize and fix <img> tags
function sanitize_img_tags_for_pdf($content) {
    return preg_replace_callback('/<img[^>]*>/i', function ($matches) {
        $img = $matches[0];

        // Extract data-src
        $dataSrc = '';
        if (preg_match('/data-src=["\']([^"\']+)["\']/', $img, $match)) {
            $dataSrc = $match[1];
        }

        // If data-src found, force-replace or insert src
        if (!empty($dataSrc)) {
            if (preg_match('/src=["\'][^"\']*["\']/', $img)) {
                $img = preg_replace('/src=["\'][^"\']*["\']/', 'src="' . esc_url($dataSrc) . '"', $img);
            } else {
                $img = preg_replace('/<img/', '<img src="' . esc_url($dataSrc) . '"', $img);
            }
        }

        // Remove lazyload classes
        $img = preg_replace('/class=["\'][^"\']*lazyload[^"\']*["\']/', '', $img);

        // Add style if not set
        if (!preg_match('/style=["\']/', $img)) {
            $img = str_replace('<img', '<img style="max-width: 708px; width: 100%; height: auto; object-fit: contain; display: block; margin-bottom: 0;"', $img);
        }

        return $img;
    }, $content);
}

// Main PDF & HTML logic
add_action('template_redirect', function () {
    if (!is_singular('post')) return;

    global $post;
    setup_postdata($post);

    $html = '<h1>' . get_the_title() . '</h1>';

    // Featured image
    if (has_post_thumbnail()) {
        $img_url = get_the_post_thumbnail_url($post, 'large');
        $html .= '<img src="' . esc_url($img_url) . '" />';
    }

    // Get and process post content
    $content_raw = get_post_field('post_content', $post->ID);
    $content_parsed = do_shortcode($content_raw);
    $post_content = apply_filters('the_content', $content_parsed);

    // Clean quotes and strip shortcodes
    $post_content = str_replace(['“', '”', '‘', '’', '�'], ['"', '"', "'", "'", ''], $post_content);
    $post_content = preg_replace('/\[(\/?vc_[^\]]+)\]/i', '', $post_content);
    $post_content = preg_replace('/\[[^\]]*?\]/i', '', $post_content);

    // Fix image data-src and cleanup
    $post_content = sanitize_img_tags_for_pdf($post_content);

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
                margin-bottom: 0px;
                object-fit: contain;
                display: block;
            }
            p {
                margin-bottom: 5px;
            }
        </style>
    ';

    // HTML preview
    if (isset($_GET['preview_pdf_html'])) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PDF Preview</title>';
        echo $css;
        echo '</head><body>';
        echo $html;
        echo '
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                document.body.innerHTML = document.body.innerHTML
                    .replace(/\[(\/?vc_[^\]]+)\]/g, "")
                    .replace(/\[[^\]]*?\]/g, "");
            });
        </script>';
        echo '</body></html>';
        exit;
    }

    // PDF generation
    if (isset($_GET['download_pdf'])) {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($css . $html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream(sanitize_title(get_the_title()) . '.pdf', ['Attachment' => true]);
        exit;
    }
});

// Add buttons to content
add_filter('the_content', function ($content) {
    if (is_singular('post') && in_the_loop() && is_main_query()) {
        $download_link = add_query_arg('download_pdf', '1', get_permalink());
        $preview_link = add_query_arg('preview_pdf_html', '1', get_permalink());

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
		
		$buttons .= '<a id="download-pdf-btn" href="' . esc_url($download_link) . '" style="' . $style . '">📄 Download PDF</a>';
		$buttons .= '<a id="preview-html-btn" href="' . esc_url($preview_link) . '" style="' . $style . '">🔍 Preview PDF HTML</a>';
		
        $buttons .= '</div>';

        return $content . $buttons;
    }
    return $content;
});
