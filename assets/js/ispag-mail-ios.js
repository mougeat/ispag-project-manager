/**
 * Brouillons d'e-mail sur iPad / iPhone. Un fichier .eml ne s'y ouvre pas en brouillon dans Mail : on récupère le texte et les fichiers
 * du serveur (…&format=files), puis la feuille de partage native (Mail, Outlook…) les reçoit avec les pièces jointes.
 * Le partage doit partir d'un appui de l'utilisateur : d'où le bouton de la fenêtre de confirmation.
 * Sans partage de fichiers (ancien navigateur) : les fichiers sont téléchargés puis un lien mailto: ouvre le message.
 * Sur PC rien ne change : ce fichier ne sert qu'aux appareils Apple.
 *
 *   ispagMailIOS.isIOS()                       -> true sur iPad / iPhone (l'iPad récent se présente comme un Mac : écran tactile)
 *   ispagMailIOS.openFromUrl(emlUrl)           -> Promise<bool> : true si le message a été partagé / ouvert
 *   ispagMailIOS.mailto({to,cc,subject,message}) : ouvre le message dans Mail (sans pièce jointe)
 */
(function () {
    'use strict';
    var T = function (s) { return typeof window.ispagT === 'function' ? window.ispagT(s) : s; };

    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function mailto(d) {
        var params = [];
        if (d.cc && String(d.cc).trim()) params.push('cc=' + encodeURIComponent(String(d.cc).trim()));
        params.push('subject=' + encodeURIComponent(d.subject || ''));
        params.push('body=' + encodeURIComponent(d.message || ''));
        window.location.href = 'mailto:' + encodeURIComponent(d.to || '') + '?' + params.join('&');
    }

    function toFiles(list) {
        return (list || []).map(function (f) {
            var bin = atob(f.data), bytes = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            return new File([bytes], f.name, { type: f.mime });
        });
    }

    function openFromUrl(emlUrl) {
        var url = emlUrl + (emlUrl.indexOf('?') === -1 ? '?' : '&') + 'format=files';
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (json) {
                if (!json || !json.success) throw new Error('server');
                var d = json.data, files = toFiles(d.files);
                var canShare = !!(navigator.share && navigator.canShare && files.length && navigator.canShare({ files: files }));
                var intro = canShare
                    ? T('The email is ready with its attachments. Tap the button, then choose Mail (or your email app). The recipient and subject are shown below: copy them if the app does not fill them in.')
                    : T('The email is ready. The attachments will be downloaded, then your email app will open.');
                var info = T('To:') + ' ' + d.to + (d.cc ? '\n' + T('Cc:') + ' ' + d.cc : '') + '\n' + T('Subject:') + ' ' + d.subject
                    + (files.length ? '\n' + T('Attachments:') + '\n- ' + files.map(function (f) { return f.name; }).join('\n- ') : '');
                return ispagConfirm(intro + '\n\n' + info, { text: true, labelOk: canShare ? T('Share the email') : T('Open the email') })
                    .then(function (ok) {
                        if (!ok) return false;
                        if (canShare) {
                            return navigator.share({ files: files, title: d.subject, text: d.message })
                                .then(function () { return true; })
                                .catch(function (e) { return e && e.name === 'AbortError' ? false : Promise.reject(e); });
                        }
                        files.forEach(function (f) {
                            var a = document.createElement('a');
                            a.href = URL.createObjectURL(f); a.download = f.name;
                            document.body.appendChild(a); a.click();
                            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 10000);
                        });
                        mailto(d);
                        return true;
                    });
            })
            .catch(function () {
                ispagAlert(T('The email draft could not be created. Please try again.'));
                return false;
            });
    }

    window.ispagMailIOS = { isIOS: isIOS, openFromUrl: openFromUrl, mailto: mailto };
})();
