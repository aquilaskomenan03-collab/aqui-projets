# -*- coding: utf-8 -*-
"""
Generateur audio gratuit pour Abbey Ambor avec la voix Windows.

Utilisation :
    python generer_audio_windows.py

Ce script cree des fichiers .wav dans assets/audio/.
Il ne demande pas de cle API et ne consomme aucun credit ElevenLabs.
Il ignore les mots qui ont deja un fichier .mp3 ou .wav.
"""

import os
import re
import subprocess
import sys
import tempfile

from generer_audio import MOTS


DOSSIER_SORTIE = os.path.join("assets", "audio")


def nom_base(mot_abbey: str) -> str:
    s = mot_abbey.strip().lower().replace(" ", "_")
    s = re.sub(r'[?/\\:*"<>|]', "", s)
    return s


def texte_powershell(texte: str) -> str:
    return "'" + texte.replace("'", "''") + "'"


def generer_wav(texte: str, chemin: str) -> None:
    script = f"""
Add-Type -AssemblyName System.Speech
$voix = New-Object System.Speech.Synthesis.SpeechSynthesizer
$voix.Rate = -2
$voix.Volume = 100
$voix.SetOutputToWaveFile({texte_powershell(chemin)})
$voix.Speak({texte_powershell(texte)})
$voix.Dispose()
"""
    with tempfile.NamedTemporaryFile("w", suffix=".ps1", delete=False, encoding="utf-8") as fichier:
        fichier.write(script)
        chemin_script = fichier.name

    try:
        resultat = subprocess.run(
            ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", chemin_script],
            capture_output=True,
            text=True,
            timeout=30,
        )
        if resultat.returncode != 0:
            raise RuntimeError(resultat.stderr.strip() or resultat.stdout.strip())
    finally:
        try:
            os.remove(chemin_script)
        except OSError:
            pass


def main() -> None:
    print("=" * 60)
    print("Generation audio Abbey Ambor - gratuite avec Windows")
    print("=" * 60)

    if not os.path.isdir("assets"):
        print("Lance ce script depuis la racine du projet abbey_app.")
        print("Exemple : cd C:\\Users\\HP\\Documents\\abbey_app")
        sys.exit(1)

    os.makedirs(DOSSIER_SORTIE, exist_ok=True)

    total = len(MOTS)
    generes = 0
    ignores = 0
    echecs = []

    for i, (abbey, francais, _categorie) in enumerate(MOTS, start=1):
        base = nom_base(abbey)
        chemin_mp3 = os.path.join(DOSSIER_SORTIE, base + ".mp3")
        chemin_wav = os.path.join(DOSSIER_SORTIE, base + ".wav")

        if os.path.exists(chemin_mp3) or os.path.exists(chemin_wav):
            ignores += 1
            print(f"[{i}/{total}] {base} deja present, ignore.")
            continue

        print(f"[{i}/{total}] {abbey} ({francais}) -> {base}.wav ...", end=" ")
        try:
            generer_wav(abbey, os.path.abspath(chemin_wav))
            generes += 1
            print("OK")
        except Exception as erreur:
            echecs.append((abbey, str(erreur)))
            print("ECHEC")

    print("\n" + "=" * 60)
    print(f"Termine. {generes} generes, {ignores} ignores, {len(echecs)} echecs.")
    if echecs:
        print("Mots en echec :")
        for abbey, erreur in echecs:
            print(f"  - {abbey}: {erreur}")
    print("=" * 60)


if __name__ == "__main__":
    main()
