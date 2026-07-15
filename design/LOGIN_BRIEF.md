# Design Brief — Familiada Host Login

An addition to the Familiada web app. Design a single screen: the login gate the
host passes through before reaching the Admin Cockpit. Front-end only; the back
end verifies a shared host password server-side (PHP session). You are designing
the screen and its states, not the auth logic.

## Context
The app has two visual worlds: the theatrical Contestant Board (the stage) and
the calm, dense Admin Cockpit (the control room). This login screen belongs to
the COCKPIT world — quiet, confident, functional. It is the first thing the host
sees, so it should set that tone: a professional instrument panel powering on,
not a game-show splash. Reuse the cockpit's type family and neutral palette. The
blue/red team colors are the board's language, not this screen's — keep it
restrained.

## Who uses it
One person — the host — at a laptop, often moments before running a live game in
front of a room. They may be under mild time pressure and typing quickly. This
screen must be fast, unambiguous, and forgiving: no hunting, no clutter.

## What's on the screen
- App identity / wordmark (Familiada), understated.
- A single password field (no username — it's one shared host password).
- A "Show password" toggle (they may be typing fast and want to check).
- A primary "Enter" / "Unlock cockpit" button — large, obvious, keyboard-submittable.
- A quiet line clarifying this is host access only; the room/board needs no login.

## States to design
- Default (empty).
- Focused / typing.
- Submitting (brief loading on the button).
- Error: wrong password — clear, calm, non-alarming inline message; field ready
  to retry, not cleared aggressively. Never reveal whether the password was
  "close."
- (Optional) Session-expired variant: same screen with a gentle "your session
  ended, sign in again" note.

## Interaction & feel notes
- Enter key submits. Autofocus the password field on load.
- Big, hard-to-misclick primary button, consistent with cockpit controls.
- Respect reduced-motion. Keyboard focus must be clearly visible.
- Works on a laptop screen; graceful down to a small window. Not a TV screen.

## The one constraint
It must feel like the same app as the Admin Cockpit — same restraint, same
family — a natural "front door" to that control room, never theatrical.

## Deliverable
The login screen with all states above. Mock nothing live; it's a static gate.
