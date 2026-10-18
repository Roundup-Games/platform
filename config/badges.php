<?php

// Badge engine configuration (M069, D208).
//
// badges.founding_table.closes_at
//   The Founding Table launch window (D187: account created during
//   launch — a different badge from Founding Supporter). Null (the
//   default — the platform is pre-launch) keeps the window open:
//   every account created while it is open qualifies. Once now passes
//   the timestamp, FoundingTableEvaluator stops proposing and no new
//   account can ever earn it; existing holds stay (OneTime, no lapse
//   — admin revocation is the removal path, spec §4).
//
//   Setting the close date is a reviewed config diff on purpose
//   (D186): badge semantics change in code review, not in an
//   evaluator hotfix.

return [
    'founding_table' => [
        'closes_at' => null,
    ],
];
