<?php
/**
 * Legacy P6-UX-8 review-prompt email — DEPRECATED as of v9.28.0 (Phase 3D).
 *
 * The original implementation scheduled a plain wp_mail() N days after order
 * completion via the `lafka_review_prompt_send` action. Phase 3D supersedes
 * the entire pipeline with:
 *
 *   - incl/conversion/lafka-review-prompt-email.php    (richer WC_Email subclass +
 *                                                       scheduling on action
 *                                                       `lafka_send_review_email`)
 *   - incl/conversion/lafka-review-prompt-banner.php   (on-site banner channel)
 *   - incl/customizer/class-lafka-customizer-reviews.php (operator controls)
 *
 * This file is now an inert shim — kept only so a third-party grep / autoload
 * lookup on the legacy path doesn't fatal. No actions are registered here.
 *
 * Migration: if a site relied on the legacy `lafka_review_prompt_*` filters
 * (subject / message / enabled / delay_days), the operator should now flip the
 * new Customizer toggle "Lafka — Review prompts > Enable review email" and
 * paste their copy into the corresponding text fields.
 *
 * @package Lafka\Plugin\Emails
 * @since   9.28.0
 * @deprecated 9.28.0 Use incl/conversion/lafka-review-prompt-email.php instead.
 */
defined( 'ABSPATH' ) || exit;

// Intentionally empty — Phase 3D owns the review-prompt pipeline.
