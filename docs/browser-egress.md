# Browser Outbound Address Rotation

Set `BROWSER_OUTBOUND_IPS` in the ignored `.env` file to a comma-separated list
of public IPv4 addresses assigned to the host. Blank preserves the existing
shared proxy. Address values are deployment configuration, not application code.

ChromeTab selects the next address under a file lock in
`var/browser-egress-counter`, shared across workers and searches. The context
gets its own loopback SOCKS proxy bound to that source address. Redirects and
subresources use the same source for the context's lifetime. Closing the tab
stops its proxy. This applies to both crawler and browser-search tabs.

The proxy still resolves and validates every destination, rejects non-public
DNS answers, and connects only to the validated address. Bound IPv4 sources use
IPv4 destinations; IPv6-only destinations fail explicitly. Invalid configuration,
unavailable source addresses and unreadable rotation state fail rather than
silently using the default source. Rotation does not retry blocked requests or
alter any rate limits.

The state file must be writable by all browser callers. If creating it manually,
give the crawler service account ownership and grant other authorized callers
access through the deployment's existing ACL scheme. Restart persistent crawler
workers when deploying the PHP changes. New request processes pick them up
automatically.
