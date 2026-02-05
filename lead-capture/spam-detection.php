<?php
/**
 * Anti-Fraud Content Validation — Gibberish Detection
 *
 * Analyzes text input for patterns consistent with spam/bot submissions.
 * Uses linguistic heuristics (consonant-to-vowel ratio, character patterns)
 * to identify gibberish without relying on external services.
 *
 * Key design decision: Returns fake success to spam bots instead of
 * showing an error. This prevents bots from reverse-engineering the
 * detection logic through trial and error.
 */

/**
 * Check if text is likely gibberish/spam
 *
 * Detection heuristics:
 * 1. Excessive consonant clusters (5+ consonants in a row)
 * 2. Extreme consonant-to-vowel ratio (>4:1 for strings longer than 6 chars)
 * 3. No vowels at all in strings longer than 3 chars
 * 4. Repeated character patterns (e.g., "aaaa", "asdfasdf")
 *
 * Normal English has roughly 1.5-2 consonants per vowel.
 * Gibberish typically has 4+ consonants per vowel.
 *
 * @param string $text Input text to analyze
 * @return bool True if text appears to be gibberish
 */
function newteam_is_gibberish($text) {
    if (empty($text)) {
        return false;
    }

    $text = strtolower(trim($text));

    // Too short to be gibberish (legitimate short names exist)
    if (strlen($text) < 4) {
        return false;
    }

    // Check for excessive consonant clusters (more than 4 consonants in a row)
    if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/i', $text)) {
        return true;
    }

    // Check consonant to vowel ratio
    $vowels = preg_match_all('/[aeiou]/i', $text);
    $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxz]/i', $text);

    // Extreme consonant ratio indicates gibberish
    if ($consonants > 0 && $vowels > 0) {
        $ratio = $consonants / $vowels;
        // Normal English: ~1.5-2 consonants per vowel
        // Gibberish: 4+ consonants per vowel
        if ($ratio > 4 && strlen($text) > 6) {
            return true;
        }
    }

    // No vowels at all in a string longer than 3 chars = gibberish
    if ($vowels === 0 && strlen($text) > 3) {
        return true;
    }

    // Repeated character patterns (like "aaaa" or keyboard mashing)
    if (preg_match('/(.)\1{3,}/', $text)) {
        return true;
    }

    return false;
}
