# -*- coding: utf-8 -*-
"""
Script de génération automatique des fichiers audio manquants
pour l'application Abbey Ambor, via l'API ElevenLabs.

UTILISATION :
    python generer_audio.py

Il va te demander ta clé API et ton Voice ID au lancement,
puis générer chaque mp3 manquant directement dans assets/audio/.

Le script est "reprenable" : si tu l'arrêtes et le relances,
il ne régénère pas les fichiers déjà présents (ça économise tes
crédits ElevenLabs si jamais ça coupe en cours de route).
"""

import os
import re
import sys
import time
import getpass

try:
    import requests
except ImportError:
    print("Le module 'requests' n'est pas installé.")
    print("Lance : pip install requests")
    sys.exit(1)

# ---------------------------------------------------------------------
# 1. Liste des mots à générer : (mot_abbey, traduction_francaise, categorie)
# ---------------------------------------------------------------------
MOTS = [
    ("babrou", "5 francs", "Argent"),
    ("babrou agnon", "10 francs", "Argent"),
    ("valet", "25 francs", "Argent"),
    ("po n'agnon", "50 francs", "Argent"),
    ("po n'ale", "100 francs", "Argent"),
    ("po n'epie", "200 francs", "Argent"),
    ("po n'neu agnon", "300 francs", "Argent"),
    ("po n'neu lonhouon", "400 francs", "Argent"),
    ("po n'obrahin", "500 francs", "Argent"),
    ("po n'obrahin ji ale", "600 francs", "Argent"),
    ("po n'obrahin ji epie", "700 francs", "Argent"),
    ("po n'obrassa ji agnon", "800 francs", "Argent"),
    ("po n'obrassa ji ale", "900 francs", "Argent"),
    ("n'gbiti", "1000 francs", "Argent"),
    ("n'gbiti po n'obrahin", "1500 francs", "Argent"),
    ("n'gbiti agnon", "2000 francs", "Argent"),
    ("n'gbiti are", "3000 francs", "Argent"),
    ("n'gbiti ale", "4000 francs", "Argent"),
    ("n'gbiti eni", "5000 francs", "Argent"),
    ("n'gbiti lonhouon", "6000 francs", "Argent"),
    ("n'gbiti lonhouon are", "7000 francs", "Argent"),
    ("n'gbiti epie", "8000 francs", "Argent"),
    ("n'gbiti gnako", "9000 francs", "Argent"),
    ("n'gbiti neu", "10000 francs", "Argent"),
    ("n'gbiti obrahin", "20000 francs", "Argent"),
    ("n'gbiti obronou", "50000 francs", "Argent"),
    ("n'gbiti ya", "100000 francs", "Argent"),
    ("n'gbiti ya agnon", "200000 francs", "Argent"),
    ("n'gbiti apke", "1000000 francs", "Argent"),
    ("m'ho", "J'ai", "Conjugaison"),
    ("f'ho", "Tu as", "Conjugaison"),
    ("n'ho", "Il/elle a", "Conjugaison"),
    ("e'ho", "Nous avons", "Conjugaison"),
    ("egn'ho", "Vous avez", "Conjugaison"),
    ("k'ho", "Ils/elles ont", "Conjugaison"),
    ("Mou le", "Je suis", "Conjugaison"),
    ("Fou le", "Tu es", "Conjugaison"),
    ("Le", "Il/elle est", "Conjugaison"),
    ("E le", "Nous sommes", "Conjugaison"),
    ("Egni le", "Vous etes", "Conjugaison"),
    ("Ke le", "Ils sont", "Conjugaison"),
    ("me dibou", "Je mange", "Conjugaison"),
    ("fe dibou", "Tu manges", "Conjugaison"),
    ("ne dibou", "Il/elle mange", "Conjugaison"),
    ("ele dibou", "Nous mangeons", "Conjugaison"),
    ("egne dibou", "Vous mangez", "Conjugaison"),
    ("que dibou", "Ils/elles mangent", "Conjugaison"),
    ("chire", "Se coucher", "Verbes"),
    ("tchamla", "Dormir", "Verbes"),
    ("non", "Marcher", "Verbes"),
    ("le beu", "Parler", "Verbes"),
    ("nehe", "Crier", "Verbes"),
    ("ro", "Chier", "Verbes"),
    ("kah bou", "Etudier", "Verbes"),
    ("oho reu", "Voyager", "Verbes"),
    ("tche", "Accompagner", "Verbes"),
    ("leu oro", "Tresser", "Verbes"),
    ("kpeinh", "Regarder", "Verbes"),
    ("lele", "Appeler", "Verbes"),
    ("sre", "Prier", "Verbes"),
    ("vie", "Chercher", "Verbes"),
    ("so", "Sortir", "Verbes"),
    ("hon", "Entrer", "Verbes"),
    ("di djouman", "Travailler", "Verbes"),
    ("odouko", "Foutou", "Nourriture"),
    ("e rerieh", "Foufou", "Nourriture"),
    ("n'bede pah", "Placali", "Nourriture"),
    ("han", "Attieke", "Nourriture"),
    ("deple", "Orange", "Nourriture"),
    ("brofoue m'gnin", "Oignon", "Nourriture"),
    ("tama t's", "Tomate", "Nourriture"),
    ("to lo", "Sauce", "Nourriture"),
    ("drouvi", "Huile", "Nourriture"),
    ("neant", "Chou", "Nourriture"),
    ("tchra n'pon", "Carotte", "Nourriture"),
    ("kpanhon", "Pain", "Nourriture"),
    ("to lo gba", "Aubergine", "Nourriture"),
    ("brofoue m'gne hehe", "Ail", "Nourriture"),
    ("ele egn' seu ahin reu", "Soyons prudent", "Expressions utiles"),
    ("e laa n' ye hou", "Ne venez pas ici", "Expressions utiles"),
    ("keke m", "Attends-moi", "Expressions utiles"),
    ("lemisero", "Depeche-toi", "Expressions utiles"),
    ("ma ho hou", "Je n'ai pas", "Expressions utiles"),
    ("m'pa hin", "J'ai oublie", "Expressions utiles"),
    ("ma non", "Je ne bois pas", "Expressions utiles"),
    ("mou nonnn", "J'ai bu", "Expressions utiles"),
    ("tchaimou", "Tais-toi", "Expressions utiles"),
    ("ele f' di", "Comment tu t'appelles ?", "Expressions utiles"),
    ("f'yesse", "Elle est belle", "Expressions utiles"),
    ("e rou mourou", "Il pleure", "Expressions utiles"),
    ("hi kanakoua", "Va etudier", "Expressions utiles"),
    ("ma kolo", "Je n'aime pas", "Expressions utiles"),
    ("kolo", "J'aime", "Expressions utiles"),
    ("n'hongo", "Il/elle est mince", "Expressions utiles"),
    ("do o mou", "C'est gros", "Expressions utiles"),
    ("le lo mou", "C'est petit", "Expressions utiles"),
    ("n'leu gnan personne", "Il est grand / elle est grande", "Expressions utiles"),
    ("fo", "C'est grand", "Expressions utiles"),
    ("n'leu redi", "Il est court", "Expressions utiles"),
    ("yafouneyi ohh", "Ou vas-tu ?", "Expressions utiles"),
    ("noun vivi", "Il est sale", "Expressions utiles"),
    ("ke dji kanien chi", "Ils ont coupe courant", "Expressions utiles"),
    ("yi wosse", "Va te laver", "Expressions utiles"),
    ("yi rechi", "Va dormir", "Expressions utiles"),
    ("lorou", "Leve-toi", "Expressions utiles"),
    ("wa dibou", "Viens manger", "Expressions utiles"),
    ("mitie", "J'ai compris", "Expressions utiles"),
    ("ho abe", "C'est trop loin", "Expressions utiles"),
    ("me yi nakouate", "Je vais a l'ecole", "Expressions utiles"),
    ("maor chiya", "Je n'ai pas d'argent", "Expressions utiles"),
    ("m'le kpe amon wou alaka", "Je regarde la tele", "Expressions utiles"),
    ("wagbe", "Viens la", "Expressions utiles"),
    ("fou se chi", "Prends ton temps", "Expressions utiles"),
    ("egn sre m'dje", "Veuillez m'excuser / Pardonnez", "Expressions utiles"),
    ("to kpo do", "Ecoute bien", "Expressions utiles"),
    ("tomobi", "Voiture", "Mots utiles"),
    ("n'gagnon", "Sable", "Mots utiles"),
    ("gbegni", "Front", "Mots utiles"),
    ("sewo fa", "Douche", "Mots utiles"),
    ("ereh", "Chaise", "Mots utiles"),
    ("ogborouvie", "Marmite", "Mots utiles"),
    ("mignin", "Piment", "Mots utiles"),
    ("djakoto", "Calecon", "Mots utiles"),
    ("kasso", "Ceinture", "Mots utiles"),
    ("sonou", "Seau", "Mots utiles"),
    ("ala", "Eponge", "Mots utiles"),
    ("n'chomolo", "Savon", "Mots utiles"),
    ("ogbo", "Fer", "Mots utiles"),
    ("ti", "Bois", "Mots utiles"),
    ("arôcô", "Fesses", "Mots utiles"),
    ("oloko", "Papaye", "Mots utiles"),
    ("roumon", "Mandarine", "Mots utiles"),
    ("kpalaho", "Sorcier", "Mots utiles"),
    ("talê", "Vetement", "Mots utiles"),
    ("kanien", "Lampe", "Mots utiles"),
    ("nakoi", "Cahier", "Mots utiles"),
    ("eyi", "Dent", "Mots utiles"),
    ("afe", "Gobelet", "Mots utiles"),
    ("cheki", "Couteau", "Mots utiles"),
    ("ngbaboua", "Chaussure", "Mots utiles"),
    ("offote", "Eglise", "Mots utiles"),
    ("m'yiko", "Belle-mere", "Famille"),
    ("m'yibou", "Beau-pere", "Famille"),
    ("nedje yipke remin", "Grand frere", "Famille"),
    ("nedje yiwo remin", "Grande soeur", "Famille"),
    ("nedje yipke afegio", "Petit frere", "Famille"),
    ("nedje yiwo afegio", "Petite soeur", "Famille"),
]

# ---------------------------------------------------------------------
# 2. Config
# ---------------------------------------------------------------------
DOSSIER_SORTIE = os.path.join("assets", "audio")
API_URL_TEMPLATE = "https://api.elevenlabs.io/v1/text-to-speech/{voice_id}"
PAUSE_ENTRE_APPELS = 0.5  # secondes, pour rester tranquille sur les limites de débit


def nom_fichier(mot_abbey: str) -> str:
    """Reproduit exactement la logique de nommage utilisée dans le code Dart."""
    s = mot_abbey.strip().lower().replace(" ", "_")
    s = re.sub(r'[?/\\:*"<>|]', "", s)
    return s + ".mp3"


def main():
    print("=" * 60)
    print("Génération audio Abbey Ambor — via ElevenLabs")
    print("=" * 60)

    if not os.path.isdir("assets"):
        print("\n⚠️  Le dossier 'assets' n'est pas trouvé ici.")
        print("Lance ce script depuis la racine du projet :")
        print("    cd C:\\Users\\HP\\Documents\\abbey_app")
        print("    python generer_audio.py")
        sys.exit(1)

    os.makedirs(DOSSIER_SORTIE, exist_ok=True)

    api_key = getpass.getpass("Colle ta clé API ElevenLabs (invisible en tapant) : ").strip()
    voice_id = input("Colle ton Voice ID ElevenLabs : ").strip()

    if not api_key or not voice_id:
        print("Clé API ou Voice ID manquant. Arrêt.")
        sys.exit(1)

    url = API_URL_TEMPLATE.format(voice_id=voice_id)
    headers = {
        "xi-api-key": api_key,
        "Content-Type": "application/json",
    }

    total = len(MOTS)
    generes = 0
    deja_presents = 0
    echecs = []

    for i, (abbey, francais, categorie) in enumerate(MOTS, start=1):
        fname = nom_fichier(abbey)
        chemin = os.path.join(DOSSIER_SORTIE, fname)

        if os.path.exists(chemin):
            deja_presents += 1
            print(f"[{i}/{total}] {fname} — déjà présent, ignoré.")
            continue

        payload = {
            "text": abbey,
            "model_id": "eleven_multilingual_v2",
            "voice_settings": {
                "stability": 0.5,
                "similarity_boost": 0.75,
            },
        }

        print(f"[{i}/{total}] Génération : \"{abbey}\" ({francais}) -> {fname} ...", end=" ")
        try:
            resp = requests.post(url, json=payload, headers=headers, timeout=30)
            if resp.status_code == 200:
                with open(chemin, "wb") as f:
                    f.write(resp.content)
                generes += 1
                print("OK")
            else:
                echecs.append((abbey, resp.status_code, resp.text[:200]))
                print(f"ÉCHEC (code {resp.status_code})")
        except requests.RequestException as e:
            echecs.append((abbey, "erreur réseau", str(e)))
            print(f"ÉCHEC (erreur réseau: {e})")

        time.sleep(PAUSE_ENTRE_APPELS)

    print("\n" + "=" * 60)
    print(f"Terminé. {generes} générés, {deja_presents} déjà présents, {len(echecs)} échecs.")
    if echecs:
        print("\nMots en échec (à réessayer plus tard, ou vérifier le compte/crédits) :")
        for abbey, code, msg in echecs:
            print(f"  - {abbey} : {code} — {msg}")
    print("=" * 60)


if __name__ == "__main__":
    main()
