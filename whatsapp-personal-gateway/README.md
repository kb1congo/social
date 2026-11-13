# WhatsApp Personal Gateway

Socle Docker d'une application Web multi-utilisateurs reposant sur :
- Nginx : reverse proxy ;
- PHP 8.4-FPM : backend métier ;
- Node.js 24 : passerelle `whatsapp-web.js` et flux SSE ;
- MySQL 8.4 : persistance.

## Prérequis

- Docker Engine ;
- Docker Compose v2 (`docker compose`) ;
- Un terminal dans le dossier du projet.

Le projet est conçu pour être portable. L'OS du VPS n'intervient pas dans la construction des images applicatives.

## 1. Préparer les secrets

```bash
cp .env.example .env
```

Remplace les valeurs `replace_with_...` dans `.env` par des secrets longs et uniques.
Ne partage jamais le fichier `.env` et ne le commite pas dans Git.

Exemple pour générer un secret depuis un environnement disposant d'OpenSSL :

```bash
openssl rand -hex 32
```

## 2. Construire et démarrer les conteneurs

```bash
docker compose build
docker compose up -d
docker compose ps
```

L'application sera accessible sur `http://localhost:8080` par défaut.

## 3. Vérifier

```bash
docker compose ps
docker compose logs --tail=100
```

Point de contrôle PHP :

```bash
curl http://localhost:8080/api/health
```

Point de contrôle Node.js (via le reverse proxy) :

```bash
curl http://localhost:8080/node-health
```

## 4. Arrêter

```bash
docker compose down
```

Les volumes nommés `mysql_data` et `whatsapp_session` sont conservés.
Pour supprimer également les données persistantes (opération destructive) :

```bash
docker compose down -v
```

## Architecture et sécurité

- Seul Nginx publie un port sur l'hôte.
- PHP, Node.js et MySQL sont reliés au réseau Docker privé `backend`.
- MySQL n'a aucun port publié.
- La session WhatsApp est stockée dans le volume `whatsapp_session`.
- PHP est l'autorité pour l'authentification et les autorisations métier.
- Les appels PHP vers Node.js utilisent `INTERNAL_API_TOKEN`.

## État du socle

Ce dépôt est un point de départ technique. L'authentification multi-utilisateurs, les rôles,
la gestion des conversations, les endpoints d'envoi/réception et la validation des jetons SSE
doivent être implémentés et testés avant toute utilisation réelle ou exposition publique.

`whatsapp-web.js` automatise WhatsApp Web et n'est pas une API officielle de Meta.
Cette approche peut subir des ruptures et comporte des risques liés aux conditions d'utilisation
et à la disponibilité du service.
