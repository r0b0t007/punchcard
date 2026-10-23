# Déclaration à la CNDP : brouillon (loi 09-08)

> Brouillon de travail, pas un avis juridique. À vérifier avec le formulaire en vigueur sur le site de la CNDP
> (www.cndp.ma) et, si possible, avec un conseil avant le dépôt. Les rubriques suivent l'ordre habituel du formulaire de
> déclaration ; adaptez-les au formulaire réel. Les choix encore ouverts sont marqués **[À DÉCIDER]**.

## 1. Responsable du traitement

- Raison sociale, forme, RC, ICE, adresse au Maroc : **[À COMPLÉTER]**
- Représentant légal et contact pour la protection des données : **[À COMPLÉTER]** (adresse e-mail dédiée, par exemple
  privacy@…)
- Qualité : responsable du traitement pour les comptes clients, la sécurité des tampons, les actualités de punchcard, les
  utilisateurs professionnels et les e-mails de service ; sous-traitant des organisations pour leurs programmes de
  fidélité. **[À DÉCIDER]** : voir la question des rôles (roles.md) ; si punchcard est seul responsable, regrouper tout
  ici.

## 2. Finalités

1. Gestion des comptes clients et de la connexion (T1).
2. Gestion de cartes de fidélité pour le compte des commerces : tampons, récompenses, remise des récompenses (T2).
3. Vérification des tampons en présence et prévention de la fraude (T3).
4. Envoi des actualités de punchcard aux clients qui y ont consenti (T4).
5. Envoi des offres des programmes suivis par le client, avec son consentement (T5).
6. Gestion des comptes professionnels (propriétaires, employés, administrateurs) et journal d'audit (T6).
7. Cartes Apple Wallet et Google Wallet, notifications (T7, prévu).
8. E-mails de service : liens de connexion, confirmations, invitations, alertes de fraude aux propriétaires (T8).

## 3. Personnes concernées

Clients des commerces (personnes physiques âgées de 16 ans ou plus, confirmé à l'inscription) ; propriétaires, employés
et administrateurs des commerces et réseaux ; administrateurs de la plateforme.

## 4. Catégories de données

- Identité et contact : nom, adresse e-mail, langue.
- Connexion : mot de passe chiffré (ou connexion par lien e-mail), réglages de double authentification, clés d'accès
  (passkeys), sessions (adresse IP, navigateur), date de dernière activité.
- Fidélité : cartes détenues, tampons (commerce, site, date, nombre, mode), récompenses (débloquées, remises).
- Sécurité : journal des passages sur les tampons (date, tampon, résultat, adresse IP, navigateur).
- Consentements : confirmation de l'âge, actualités de punchcard, programmes suivis, avec leurs dates.
- Professionnels : rôle par commerce, invitations, actions de la plateforme (journal d'audit).
- Vérification des commerces : copie de la pièce d'identité du propriétaire (carte nationale ou passeport) et
  justificatif d'adresse du commerce, chiffrés, consultés par l'administrateur de la plateforme seulement, chaque
  consultation journalisée. **[À vérifier : la copie de la carte nationale peut relever de l'autorisation préalable
  plutôt que de la simple déclaration.]**
- Aucune donnée sensible au sens de l'article 1 de la loi 09-08. Pas de date de naissance pour l'instant
  **[à revoir si l'anniversaire est ajouté]**.

## 5. Origine des données

Directement auprès de la personne (inscription, utilisation de l'application) ; les tampons proviennent des passages sur
les tampons NFC, des QR scannés par le personnel et des tampons ajoutés par le personnel.

## 6. Destinataires

- punchcard (personnel habilité, besoin d'en connaître).
- L'organisation qui gère le programme (ses administrateurs) ; dans un réseau de franchise, chaque franchisé ne reçoit
  que les clients qui ont tamponné chez lui en personne.
- Sous-traitants : hébergeur Oracle Cloud Infrastructure (région Maroc Ouest, Casablanca ; ADR 0008), Cloudflare
  (réseau), fournisseur d'e-mails **[À CHOISIR]**, suivi d'erreurs (Sentry, prévu) ; plus tard Apple et Google (Wallet),
  services de notification des navigateurs.

## 7. Transferts vers l'étranger

Hébergement au Maroc (ADR 0008) :

- **Base de données, fichiers et sauvegardes** : hébergés au Maroc, chez Oracle Cloud Infrastructure, région Maroc Ouest
  (Casablanca), sur une machine virtuelle réservée à punchcard (matériel partagé du prestataire), dans un compartiment et
  un réseau qui lui sont propres ; les sauvegardes chiffrées restent dans la même région. Pas de transfert de la base de
  données hors du Maroc ; finalités 1 à 8.
- **Prestataire de droit américain** : Oracle est une société américaine, bien que les données restent au Maroc.
  **[À vérifier avec la CNDP : l'accès à distance de son support compte-t-il comme un transfert ?]**
- **Transferts liés aux sous-traitants situés hors du Maroc** : à déclarer ou à autoriser selon le pays.
    - Cloudflare (réseau) : tout le trafic transite par ses serveurs hors du Maroc, déchiffré (connexions, tampons,
      exports, documents envoyés), sans y être conservé comme une base de données.
    - Fournisseur d'e-mails : adresses et contenu des messages, conservés selon sa propre durée **[À CHOISIR]**.
    - Suivi d'erreurs (Sentry, prévu) : identifiant de l'utilisateur et contexte de la requête en cas d'erreur.
    - Plus tard Apple, Google et les services de notification.

## 8. Durées de conservation

Voir retention.md. En résumé : compte client jusqu'à sa suppression, ou 24 mois sans activité (préavis par e-mail 30
jours avant) puis suppression ou anonymisation ; tampons et récompenses conservés sans l'identité de la personne ;
journal des passages 180 jours ; liens de connexion 1 jour ; invitations 1 mois ; documents de vérification des
commerces 3 mois après la décision de la plateforme (acceptés ou refusés), supprimés chaque nuit, et en attente
d'une décision sans limite pour l'instant **[À DÉCIDER]** ; journal d'audit **[À DÉCIDER]**. Les sauvegardes
chiffrées gardent les données supprimées jusqu'à leur expiration (30 jours proposés **[À DÉCIDER]**), puis plus rien.

## 9. Droits des personnes

- Information : notice de confidentialité publiée sur le site et dans l'application (privacy-notice.draft.md).
- Accès : téléchargement des données au format JSON depuis les réglages, après avoir confirmé son identité.
- Rectification : réglages du profil.
- Opposition : interrupteurs de consentement sur chaque carte et dans les réglages ; lien de désinscription dans chaque
  message **[à mettre en place]**. Chaque changement de consentement est journalisé (date, origine).
- Suppression : suppression du compte depuis les réglages.
- Contact : **[adresse e-mail dédiée]**, réponse sous 30 jours.

## 10. Mesures de sécurité

Chiffrement des échanges (HTTPS) ; mots de passe chiffrés ; double authentification et clés d'accès ; cloisonnement des
données entre organisations et entre franchisés, vérifié par des tests automatisés ; clés des tampons NFC et certificats
hors du code et de la base ; aucune donnée personnelle dans les journaux techniques ; journal d'audit des actions des
administrateurs ; sauvegardes chiffrées chaque nuit, conservées au Maroc dans la même région **[CHW-59]**.

## 11. Liste de contrôle avant le dépôt

- [x] Hébergement décidé (ADR 0008) : au Maroc, Oracle Cloud, Casablanca.
- [ ] Position de la CNDP sur un hébergeur de droit américain dont les données restent au Maroc.
- [ ] Question des rôles tranchée (roles.md) : un dépôt pour punchcard, et un par organisation si elles sont
      responsables.
- [ ] Sous-traitants choisis et listés (e-mails, suivi d'erreurs).
- [ ] Formulaire en vigueur téléchargé sur www.cndp.ma et rubriques reportées.
- [ ] Chaque mesure marquée **[à mettre en place]** est en service dans l'application (droits, journal des
      consentements, désinscription, règle d'inactivité).
- [ ] Régime des copies de pièces d'identité vérifié (déclaration ou autorisation préalable).
- [ ] Dépôt **avant toute donnée réelle** (avant le pilote, CHW-37).
- [ ] Numéro de récépissé noté et imprimé dans la notice de confidentialité.
- [ ] Notice et conditions professionnelles publiées sur le site.
