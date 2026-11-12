const https = require('https');
const fs = require('fs');

const mots = [
  ['eryho', 'eryho'], ['a hin ho', 'a_hin_ho'], ['anou ho', 'anou_ho'],
  ['nasse', 'nasse'], ['nasse oh', 'nasse_oh'], ['e bah nou miti for', 'e_bah_nou_miti_for'],
  ['gnan chi', 'gnan_chi'], ['ce ye fan', 'ce_ye_fan'], ['ce ye man', 'ce_ye_man'],
  ['okwaba', 'okwaba'], ['ah oo', 'ah_oo'], ['an hoo', 'an_hoo'],
  ['Nkpoo', 'nkpoo'], ['Agnon', 'agnon'], ['Are', 'are'], ['Ale', 'ale'],
  ['Oni', 'oni'], ['Lonhuon', 'lonhuon'], ['Lonhouon are', 'lonhouon_are'],
  ['Epihe', 'epihe'], ['Gnako', 'gnako'], ['Enin', 'enin'],
  ['ro', 'ro'], ['tchra', 'tchra'], ['rolopi', 'rolopi'], ['orovi', 'orovi'],
  ['kaie', 'kaie'], ['an houon', 'an_houon'], ['midji', 'midji'], ['laa', 'laa'],
  ['re ni', 're_ni'], ['di bou', 'di_bou'], ['nhon', 'nhon'],
  ['la hin', 'la_hin'], ['yo hou', 'yo_hou'], ['levi', 'levi'], ['lo fou', 'lo_fou'],
  ['de', 'de'], ['ya', 'ya'], ['nan dje hipke', 'nan_dje_hipke'],
  ['nan dje hiyor', 'nan_dje_hiyor'], ['dje', 'dje'],
  ['sa ka', 'sa_ka'], ['fe des', 'fe_des'], ['an bou', 'an_bou'], ['ande', 'ande'],
];

function generer(texte, fichier) {
  return new Promise((resolve, reject) => {
    const url = `https://translate.google.com/translate_tts?ie=UTF-8&q=${encodeURIComponent(texte)}&tl=fr&client=tw-ob`;
    https.get(url, (res) => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => {
        const buffer = Buffer.concat(chunks);
        fs.writeFileSync(`assets/audio/${fichier}.mp3`, buffer);
        console.log(`✅ ${fichier}.mp3 (${buffer.length} bytes)`);
        resolve();
      });
    }).on('error', reject);
  });
}

async function main() {
  for (const [texte, fichier] of mots) {
    await generer(texte, fichier);
    await new Promise(r => setTimeout(r, 300));
  }
  console.log('🎉 Tous les audios sont générés !');
}

main();