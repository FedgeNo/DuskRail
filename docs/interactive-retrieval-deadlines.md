# Interactive Retrieval Deadline

Interactive requests have 60 seconds from enqueueing to make content available.
`InteractiveRetrievals` stores the original item ID, current redirect target,
deadline, and terminal failure. Redirect merges preserve the deadline.

The status endpoint returns `state: failed` and `reason` after a failed attempt
or deadline expiry. HTTP 429/503 ends the interactive attempt immediately.
Worker exits report incomplete attempts rather than waiting for claim expiry.
The manager also expires queued or stuck attempts independently of polling.

Failure clears `Items.crawlPriority`; the URL and captured content remain for
ordinary crawling. Interactive timeout is not a dead-URL verdict. Existing
rules for genuine permanent failures and unresolved challenges still apply.

The enqueue response includes the Unix `deadline` so callers can bound their
own progress-connection wait. An explicit new submission starts a new budget.
