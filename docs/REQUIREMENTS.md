# Requirements

Plain-language record of what was agreed. The [`ARCHITECTURE.md`](ARCHITECTURE.md) turns these into technical decisions; [`GAME_RULES.md`](GAME_RULES.md) turns the gameplay ones into exact rules.

## Content authoring
- The admin can **create their own questions and answers**, each answer with a **point value**.
- Questions are grouped into **sets** (a set = one round's worth of questions/board).

## Two live views, one game
- **Contestant view** (big screen): shows the board the room watches.
- **Admin view** (cockpit): the host sees **all answers** with points and which are revealed.
- When the contestant gives an answer, the host can see whether it matches something on the list, and reveal it.
- The contestant board **auto-refreshes when the admin clicks** (achieved via ~1s polling — confirmed feasible on cPanel).

## Strikes & steal (per round)
- The admin can **strike / buzz out** an answer that isn't on the list.
- Strikes are tracked **for the team that started the round**:
  - **1 strike:** nothing happens.
  - **2 strikes:** the other team may quietly discuss likely answers.
  - **3 strikes:** the question passes to the **second team**.
- The second team gets **one chance**. If their single answer is on the board → they **take over the points**. If not → strike, and the **points go to team 1**.

## Scoring
- The app **sums the points** of all revealed answers.
- The admin **chooses which team (blue or red)** the current answers are credited to.
- The admin **finishes the round**, and the round total is added to that team.
- Then the game moves to the **next set of questions**.

## Game modes (chosen when designing the game)
- **Classic (original):** rounds 1, 2, 3 = ×1; round 4 = ×2; round 5 = ×3. Game ends when a team reaches **300 points**.
- **Free rounds:** every round ×1, as many rounds as you want; the team with the higher score wins.

## Grand finale (optional add-on for either mode)
- **5 questions.** Contestant 1 answers within **15s**; contestant 2 gets the **same questions** within **20s**.
- The admin **records the answers** and **manually checks** whether each matches a correct answer with assigned points.
- **The admin can pause the finale clock** (and resume it).

## Sounds
- The admin can **set sounds** for correct answers, strikes, and other needed cues.
- Cue list to cover: correct answer, strike/buzzer, round start, round end, game start, end-of-game.

## Game management & history
- The admin can **design multiple games**.
- There is a **history** of games with their **status**: was it played and finished? not finished?
- **Unfinished games can be resumed.**
- The admin **chooses which designed game is the "live" one** for the contestants (only one live at a time).

## Platform decision
- Build in **PHP / HTML / CSS / MySQL**. The cPanel Django/Node/Rails option was considered and **declined** — not needed for this game, adds complexity. (See ARCHITECTURE "Real-time".)
