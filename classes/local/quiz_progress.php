<?php
// This file is part of Moodle - https://moodle.org/

namespace local_ragassistant\local;

/**
 * Saved progress of a /quiz attempt, kept next to the quiz in the chat session history.
 *
 * chat.js sends the whole attempt state after every step (quiz_progress.php) and gets it
 * back in the page after a reload, so the student continues where they were: the same
 * question order, the picked option, the checked feedback, or the results page.
 *
 * @package local_ragassistant
 */
class quiz_progress {
    /**
     * Quiz ids are made by index.php when the quiz arrives (random_string(16)).
     *
     * @param mixed $id
     * @return bool
     */
    public static function valid_id($id): bool {
        return is_string($id) && preg_match('/^[A-Za-z0-9]{16}$/', $id) === 1;
    }

    /**
     * Validate an attempt state for a quiz with $count questions.
     *
     * Shape: order (the question indices in the order asked), optionorder (per question,
     * the option indices in the order shown), position (0..count, count = results page),
     * picked (option index or null), checked (bool), answers (per question index: option
     * index or null).
     *
     * @param mixed $value
     * @param int $count Number of questions in the quiz.
     * @return array|null The clean state, or null when anything is off.
     */
    public static function clean($value, int $count): ?array {
        if (!is_array($value) || $count < 1) {
            return null;
        }

        $order = $value['order'] ?? null;
        if (!self::is_permutation($order, $count)) {
            return null;
        }

        $optionorder = $value['optionorder'] ?? null;
        if (!is_array($optionorder) || count($optionorder) !== $count) {
            return null;
        }
        $optionorder = array_values($optionorder);
        foreach ($optionorder as $options) {
            if (!self::is_permutation($options, 4)) {
                return null;
            }
        }

        $position = $value['position'] ?? null;
        if (!is_int($position) || $position < 0 || $position > $count) {
            return null;
        }

        $picked = $value['picked'] ?? null;
        if ($picked !== null && (!is_int($picked) || $picked < 0 || $picked > 3)) {
            return null;
        }

        $checked = $value['checked'] ?? false;
        if (!is_bool($checked)) {
            return null;
        }

        $answers = $value['answers'] ?? [];
        if (!is_array($answers) || count($answers) > $count) {
            return null;
        }
        $cleananswers = [];
        foreach (array_values($answers) as $answer) {
            if ($answer !== null && (!is_int($answer) || $answer < 0 || $answer > 3)) {
                return null;
            }
            $cleananswers[] = $answer;
        }

        return [
            'order' => array_values($order),
            'optionorder' => $optionorder,
            'position' => $position,
            'picked' => $position < $count ? $picked : null,
            'checked' => $position < $count && $checked,
            'answers' => $cleananswers,
        ];
    }

    /**
     * Whether $list holds each of 0..$size-1 exactly once.
     *
     * @param mixed $list
     * @param int $size
     * @return bool
     */
    private static function is_permutation($list, int $size): bool {
        if (!is_array($list) || count($list) !== $size) {
            return false;
        }
        foreach ($list as $item) {
            if (!is_int($item)) {
                return false;
            }
        }
        $sorted = array_values($list);
        sort($sorted);
        return $sorted === range(0, $size - 1);
    }
}
