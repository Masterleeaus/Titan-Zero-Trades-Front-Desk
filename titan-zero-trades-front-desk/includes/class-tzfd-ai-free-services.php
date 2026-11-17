<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Loads the Free AI lane classes (issues #35, #36, #39, #40, #41). Every class here works without
 * consent, a connector or a key. Hosted calls only happen after consent and through the connector filter.
 */
require_once __DIR__ . '/class-tzfd-ai-free-core.php';
require_once __DIR__ . '/class-tzfd-ai-knowledge-builder.php';
require_once __DIR__ . '/class-tzfd-ai-gap-detection.php';
require_once __DIR__ . '/class-tzfd-ai-gap-page.php';
require_once __DIR__ . '/class-tzfd-ai-faq-suggestions.php';
require_once __DIR__ . '/class-tzfd-ai-setup-check.php';
require_once __DIR__ . '/class-tzfd-ai-monthly-insights.php';
// Basic multilingual replies (#26): locale rules, reviewed fixed texts and consent-gated hosted translation.
require_once __DIR__ . '/ai-assist/class-tzfd-ai-multilingual.php';
require_once __DIR__ . '/class-tzfd-ai-answer-box.php';
TZFD_Ai_Gap_Detection::register();
TZFD_Ai_Gap_Page::register();
TZFD_Ai_Faq_Suggestions::register();
// Any publish, unpublish or edit of a FAQ entry drops the cached public answers (#39 -> #20 cache).
add_action( TZFD_Ai_Knowledge_Builder::CHANGED_ACTION, array( 'TZFD_AI_Answer_Box', 'invalidate' ) );
