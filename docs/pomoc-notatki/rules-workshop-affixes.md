# Notes: rules-workshop-affixes (51 patterns x 5 locales)

Files written (php -l clean, same 51 keys and same field sets in pl, en, fr, de, pt_BR):
`api/lang/<locale>/help/rules.rules-workshop-affixes.php`. The data and the generator are in
`scratchpad/help-gen/` (`data_<locale>.py`, `gen_rwa.py`); the PHP files are the deliverable, not the generator.

## Sources used, per group

- Affixes per rarity, definitions, unique: `content/rules/starter.json` (`affixes`), `content/schema/rules.schema.json`
  (`affix`, `affixCount`, `affixUnique`), `api/app/Domain/Market/Affixes.php` (count drawn evenly min..max, pool by slot,
  weights, no repeats), `api/app/Services/PartAffixes.php` and `PartMerges.php` (merge keeps affixes, draws the missing ones),
  `api/app/Content/Rules.php` (`scaledStats`, `affixCutPpm`, `surfaceAffixPpm`, `uniqueSpecialPpm`, `withUniqueAffixes`,
  `effectiveConditionPpm`), `api/app/Content/RunInputsFactory.php` (`surface_loss`, `mass_penalty`, `lag_cut` read by ROLE),
  `api/app/Content/PackValidator.php` (max >= min), `docs/api-runs.md` "Afiksy części (A1/A2)", web `part.affix.*`/`part.unique.*`.
- Workshop (mechanic, queue, removal, base times): `Rules::mechanic`, `workshopMaxQueue`, `workshopJobMs`, `partRepairMs`,
  `serviceJobMs`, `Services/Workshop.php` (`queue_full`: pending jobs of the city + jobs of the plan > max), `WorkshopGear::workers`,
  `docs/api-runs.md` "Warsztat", "Wyposażenie warsztatu".
- Bays, mechanics, equipment: `Rules::gearMax`, `gearNextPriceCents`, `gearTimePpm`, `gearFeeCents`, `equipmentItemOf`,
  `WorkshopEquipment`, schema `purchase_prices`/`equipment_level`, `content/README.md`, web `workshop.gear.*` (the game's names of
  the items and levels).
- Upgrade +N: `Rules::statPpm`, `upgradeMax`, `upgradeCap`, `upgradeCostCents`, `upgradeJobMs`, `docs/api-runs.md` "Ulepszanie
  części" (examples with measured numbers: tires T2 $570 steps, 8.0..29.8 s). The lap ceiling 1.6 is `TrackProfile` (clamp stat/reference).
- Merge: `Rules::mergeChancePpm`, `mergeCostCents`, `mergeJobMs`, `mergeScrapCents`, `PartMerges::resolve`, docs "Łączenie części".
- Example numbers are my arithmetic from the real values: 120 s x 0.98 = 117.6 s; removal x 60% = 70.6 s; lift 166.6 s x 68/45/36%;
  engine 588 s x 33/27%; tires with machine 35% and electric toolkit 80% = 32.9 s; pity 25% + 1% + 5 x 3% = 41%.

## UNSURE

- `/affixes/unique/*/ppm`: which slot a special effect works on is decided by the CODE by role (`surface_loss` only on the tires
  role, `mass_penalty` on the suspension role, `lag_cut` on the boost role; `braking_condition` is applied in any slot that has it),
  not by the schema. The text names the four current slots and explains each effect; it does not promise what happens if
  a special is moved to another slot.
- `/affixes/unique/*/mods/*/ppm`: the text says the modifier kind (power, fuel, reliability) is set in the rules text, because the
  Studio only exposes the number. I did not check how the panel's "Zasady jako tekst" page presents the unique mods.

## Inconsistencies in the code or docs that you should know about

1. RESOLVED (unit detection fixed by the coordinator): `/workshop/merge/base_ppm/{uncommon,rare,epic,legendary}` are now shown in % and `/workshop/base_time_ms/*` in seconds; those 5 entries were rewritten in all five locales for the display unit (labels with (%) / (s), examples 80% / 120 s, ranges 0,0001 - 100% and from 1 s).
2. `bays/price_cents[0]`, `mechanics/price_cents[0]` and the `price_cents` of level 1 of every equipment item are NEVER READ by the
   code (`gearNextPriceCents` reads index/level + 1; the start state is free). The panel shows them as editable numbers; the texts say
   the game never charges them.
3. The schema (`purchase_prices`) says every price is higher than the one before, but I found no validator that enforces it
   (PackValidator has nothing on `price_cents`). The texts say "should be higher" (pl "powinna", en "should").
4. `workshop.max_queue` schema description: "the one that is done included". The code counts jobs whose status is not done,
   so it is the job being done (in progress) that is included, not a finished one. I wrote "the job the mechanic is doing now".
5. `workshop.time_cut_ppm_per_level` is 20000 now, which is exactly the schema maximum (2%): the Studio slider cannot raise it.
6. `fee_cents` is read for any equipment item that has `slots` (tire machine, lift, hoist) but never for the toolkit (it has no
   slots), although the schema allows it on every level. Today only `tire_machine` level 1 has a fee, so only that pattern exists
   in the catalog; if a fee is added to a lift or hoist level, those patterns have no entry (the panel would show the schema text).
7. `workshop.mechanic_level` cannot be changed by a player in the game (docs: "poziom mechanika zostaje 1"); the text says it is a
   constant of the game. The merge bonus uses the same constant (`level_ppm` x `mechanic_level`).
8. The panel's group line for equipment levels shows the NAME of the level (`Levels > None`, `> Manual`), while my text calls the star
   the number of the level (1, 2, 3) and gives the names in the help; both are in the example so an operator can match them.
9. The lap ceiling (stat / reference at most 1.6) is a code constant in `TrackProfile`, not a rule number; mentioned in the tip of
   `upgrade/stat_ppm_per_level` as the reason why +N may give nothing on easy tracks (docs measured this for Złomowo/Autodrom).
