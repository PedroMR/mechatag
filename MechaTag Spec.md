# MechaTag - Spec for the Digital Game

MechaTag is a board game. We want to create the digital version for it.

## Platforms

The main game logic and multiplayer structure should be implemented in PHP with a filesystem-based data backend.

The client will be in Javascript.

## Game Overview

In the game, 2 to 4 players divided in teams draft mech modules and configure 2 mechs per team. The teams are then pitted against each other in the arena. They then draft more modules and fight another time. This is repeated until a team has earned 2 victories, and that team is the winner of the game overall.

Game rules are at https://docs.google.com/document/d/e/2PACX-1vSgJoOPCIEu9h5LDThu86ryLATy8trdLmPxeQn3qL5r7N32Bh0QNS319YzHj1ZnxDK5jb2U3glKhsjp/pub

Game cards are at https://docs.google.com/spreadsheets/d/e/2PACX-1vQYixM7NqCPCDOBE7TlpTizYIwZ87UJXZPGNn4wragvM1P6F6LyW2EzB6xu1d9wIbNfAJxKe7z4mSIM/pubhtml in the Modules and Pilot Actions tables.

## Digital features

* User names
* Rooms for 2 to 4 players
* Players should be able to allocate themselves to teams before the game begins, or randomize the teams
* Players should be able to text chat from the moment they're in a room
* Players should be able to start the game once they're divided in 2 teams
* Once the game starts, players should have a new tab for team chat, one that only their teammate can read
* While in drafting mode:
* * Players can select a card to be picked and confirm when another player in the team selected a card.
* * Once two cards were selected by each team the draft automatically proceeds.
* While in fighting mode:
* * Players should have a button that plays back the next card flipped from the Fight Deck and the results of that action
* * Some actions require a selection to be done by a player (like MOVE or LOCK)
