# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MechaTag is a digital adaptation of a physical board game. This is a greenfield project — implementation has not started yet.

- **Spec document:** `MechaTag Spec.md` — read this first for game rules and feature requirements
- **Game rules:** https://docs.google.com/document/d/e/2PACX-1vSgJoOPCIEu9h5LDThu86ryLATy8trdLmPxeQn3qL5r7N32Bh0QNS319YzHj1ZnxDK5jb2U3glKhsjp/pub
- **Card data:** https://docs.google.com/spreadsheets/d/e/2PACX-1vQYixM7NqCPCDOBE7TlpTizYIwZ87UJXZPGNn4wragvM1P6F6LyW2EzB6xu1d9wIbNfAJxKe7z4mSIM/pubhtml (Modules and Pilot Actions tables)

## Architecture

**Backend:** PHP with a filesystem-based data backend (no database). Concurrent writes use `flock`-based file locking.

**Frontend:** Alpine.js (no build step). Game state is kept fresh via client-side polling.

**Sessions:** Session-local nicknames only — no accounts or passwords. Players can close the browser and resume their session.

## Game Structure

The game proceeds in alternating draft/fight rounds:
1. Players draft mech modules and configure 2 mechs per team
2. Teams fight in the arena
3. Repeat until one team earns 2 victories

Key game entities: teams (2–4 players split into 2 teams), mechs (2 per team), modules (drafted cards), pilots, pilot actions.

**Fight Deck:** After mech configuration, the pilot action cards of all participating pilots are shuffled together into a single Fight Deck for that round's battle.

## Key Features to Implement

- Rooms supporting 2–4 players with team assignment (manual or randomized)
- Persistent text chat (global and team-only channels)
- Draft phase: players select cards collaboratively per team; draft auto-advances once both team members confirm picks
- Fight phase: players flip cards from the Fight Deck sequentially; some actions (MOVE, LOCK) require interactive player selection
