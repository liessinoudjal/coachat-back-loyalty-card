# Module Avis Google

## Ce qui a ete implemente

- Configuration merchant dediee via `MerchantGoogleReviewModule` avec validations de completion et whitelist stricte des URLs Google.
- Parcours customer base sur deux signaux seulement: clic sortant vers Google puis retour confirme dans l'app.
- Session unique reutilisable par couple customer x merchant tant qu'aucune nouvelle session n'est necessaire.
- Attribution idempotente d'une recompense unique par session avec selection uniforme parmi les reward options actives.
- QR token mono-usage redeemable uniquement par le merchant proprietaire de la recompense.
- Journalisation des evenements metier principaux (`OUTBOUND_CLICKED`, `RETURN_CONFIRMED`, `WHEEL_SPUN`, `REWARD_REVEALED`, `REWARD_REDEEMED`) et endpoint applicatif optionnel pour des evenements client supplementaires.

## Decisions V1

- Les reward options sont stockees en JSON pour limiter la surface du modele et garder l'evolution vers des poids/probabilites ouverte.
- Aucune verification d'avis Google reel ni de note n'est tentee par le backend.
- Les payloads sont serializes manuellement dans les controleurs pour conserver un contrat HTTP stable cote frontend.
- Le spin utilise une transaction Doctrine avec verrou pessimiste sur la session pour garantir l'idempotence et l'unicite de la recompense.

## Points d'extension prevus

- Cooldown journalier ou hebdomadaire par merchant/customer.
- Probabilites ponderees par reward option.
- Expiration differenciee par type de recompense.