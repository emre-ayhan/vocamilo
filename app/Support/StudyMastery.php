<?php

namespace App\Support;

use App\Models\StudySession;

class StudyMastery
{
    // Only completed v4 snapshots are evidence. Older scores never manufacture mastery.
    public static function forLearner(int $collection, int $user): array
    {
        $states = [];
        $sessions = StudySession::where('collection_id', $collection)->where('user_id', $user)->where('mode', 'study')->where('rules_version', '>=', 4)->whereNotNull('completed_at')->withMax('answers', 'id')->orderBy('answers_max_id')->get();
        foreach ($sessions as $session) {
            foreach ($session->questions as $q) {
                if (($q['mastery_applied'] ?? false) !== true) {
                    continue;
                }
                $states[$q['id']] = ['stage' => $q['mastery_stage'], 'qualified' => (bool) $q['mastery_qualified'], 'baseline' => $session->id, 'reviewed_at' => $session->completed_at->getTimestamp(), 'sequence' => $session->answers_max_id];
            }
        }

        return $states;
    }
}
