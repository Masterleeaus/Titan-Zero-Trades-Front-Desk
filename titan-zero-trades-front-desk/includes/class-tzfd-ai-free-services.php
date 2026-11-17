<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Loads the Free AI lane classes (issues #35, #36, #39, #40, #41). Every class here works without
 * consent, a connector or a key. Hosted calls only happen after consent and through the connector filter.
 */
require_once __DIR__ . '/class-tzfd-ai-free-core.php';
require_once __DIR__ . '/class-tzfd-ai-knowledge-builder.php';
require_once __DIR__ . '/class-tzfd-ai-gap-detection.php';
require_once __DIR__ . '/class-tzfd-ai-faq-suggestions.php';
require_once __DIR__ . '/class-tzfd-ai-setup-check.php';
require_once __DIR__ . '/class-tzfd-ai-monthly-insights.php';
TZFD_Ai_Gap_Detection::register();
