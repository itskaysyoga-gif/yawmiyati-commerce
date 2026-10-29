---
name: creative-qa
description: CUSTOM (Yawmiyati). Use after rendering and before asking for approval - checks visual identity, Arabic on screen, evidence and offer consistency of studio records.
---
# Creative QA (custom skill, Yawmiyati)

- `node studio/qa.mjs`: files present and unchanged, H.264 at the right size and 10-35 s, no digits or banned words in
  the words layer, tagged link in the caption. Marks qa_passed / qa_failed on each record.
- Then look: extract frames (`ffmpeg -ss <t> -i <mp4> -frames:v 1 out.png`) at the hook, each beat and the offer card.
  Check: Arabic joined and right-to-left, nothing under the top 220 px or bottom 380 px, readable with sound off,
  the photo is the exact item, the offer card equals the store page today, the country is named, the draft tag is
  shown while media rights or dialect review are open.
- Never call a photo-motion piece a demonstration. Never accept generated product imagery.
- Report what a person still has to check (native reading, rights) instead of passing it silently.
