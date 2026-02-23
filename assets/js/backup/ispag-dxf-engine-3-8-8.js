/**
 * ISPAG DXF ENGINE - Version 3.8.8 (FULL RESTORE)
 * - FIX : Rétablissement complet du cartouche (toutes les lignes de données)
 * - FIX : Rétablissement des graduations 15° sur la vue de dessus
 * - FEATURE : Piquage Purge vertical si Elevation >= Htot
 * - FEATURE : Tubulures rectangulaires en vue de dessus
 * - COMPATIBILITÉ : Remplacement des " par '' pour l'affichage des pouces
 */
const IspagDxfEngine = {
    config: {
        layers: {
            FONDS: { color: 250 },
            VIROLE: { color: 250 },
            SUPPORTS: { color: 251 },
            PIQUAGES: { color: 250 },
            PIQUAGES_ARRIERE: { color: 253 },
            COTATIONS: { color: 252 },
            TABLEAU: { color: 250 },
            CARTOUCHE: { color: 250 },
            CADRE: { color: 250 }
        },
        textHeight: 25,
        nozzleLength: 100,
        cartWidth: 1000,
        maxPiquagesPerPage: 15
    },

    generateEntities: function(specs, project = {}) {
        const dim = specs.dimensions_principales || {};
        const D = parseFloat(dim.Diametre_mm || 0);
        const Htot = parseFloat(dim.Hauteur_mm || 0);
        const R = D / 2;
        const GC = parseFloat(dim.Ground_clearance || 50);
        const f = parseFloat(dim.Bottom_Height_mm || 280);
        const supportType = (dim.Support || '').toLowerCase();
        
        let entities = [];

        // --- 1. COORDONNÉES DE MISE EN PAGE ---
        const faceX = 0;
        const dessusX = R + 1000;
        const dessusY = Htot - R;
        const tableauX = dessusX - R;
        const tableauY = dessusY - R - 200;
        const cx = (dessusX + R + 500) - 200; // Position X du cartouche
        const yTop = Htot + 450;
        const yBot = -450;

        // --- 2. DESSIN VUE DE FACE (Cuve) ---
        entities.push(this.createEllipse(faceX, GC + f, R, f/R, Math.PI, 2 * Math.PI, "FONDS"));
        entities.push(this.createEllipse(faceX, Htot - f, R, f/R, 0, Math.PI, "FONDS"));
        entities.push(this.createLine(faceX - R, GC + f, faceX - R, Htot - f, "VIROLE"));
        entities.push(this.createLine(faceX + R, GC + f, faceX + R, Htot - f, "VIROLE"));

        // Pieds / Support
        if (supportType.includes('ring') || supportType.includes('virole')) {
            const Rv = R - 100;
            const dy = Math.sqrt(Math.pow(f, 2) * (1 - Math.pow(Rv, 2) / Math.pow(R, 2)));
            const yContact = (GC + f) - dy;
            entities.push(this.createLine(faceX - Rv, 0, faceX + Rv, 0, "SUPPORTS"));
            entities.push(this.createLine(faceX - Rv, yContact, faceX + Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(faceX - Rv, 0, faceX - Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(faceX + Rv, 0, faceX + Rv, yContact, "SUPPORTS"));
        }

        // --- 3. VUE DE DESSUS (Cercle + Graduations 15°) ---
        entities.push(this.createUniversalEllipse(dessusX, dessusY, R, 1, "VIROLE"));
        for (let a = 0; a < 360; a += 15) {
            const rad = ((a - 90) * Math.PI) / 180;
            entities.push(this.createLine(
                dessusX + (R - 50) * Math.cos(rad), dessusY + (R - 50) * Math.sin(rad),
                dessusX + R * Math.cos(rad), dessusY + R * Math.sin(rad),
                "COTATIONS"
            ));
        }

        // --- 4. PIQUAGES (Logique Face + Dessus) ---
        const angleRegistry = {};
        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            specs.piquages_techniques.forEach((p, index) => {
                const ang = parseFloat(p.Angle_degres || 0);
                const alt = parseFloat(p.Elevation_mm || 0);
                const dI = parseFloat(p.Bride_Int_mm || 50);
                const rad = (ang * Math.PI) / 180;
                const cosF = Math.cos(rad);
                const sinF = Math.sin(rad);
                const layer = cosF >= -0.001 ? "PIQUAGES" : "PIQUAGES_ARRIERE";

                // VUE DE FACE
                if (alt >= Htot - 10) { 
                    // Mode Purge (Rectangle vertical)
                    const xp = faceX + (R * sinF * 0.4);
                    entities.push(this.createLine(xp - dI/2, Htot, xp - dI/2, Htot + 100, layer));
                    entities.push(this.createLine(xp + dI/2, Htot, xp + dI/2, Htot + 100, layer));
                    entities.push(this.createLine(xp - dI/2, Htot + 100, xp + dI/2, Htot + 100, layer));
                    entities.push(this.createMText(xp, Htot + 130, `P${index}`, 0, layer));
                } else {
                    // Mode Standard (Latéral)
                    const xBase = faceX + (R * sinF);
                    const sq = Math.abs(cosF);
                    const side = sinF >= 0 ? 1 : -1;
                    const xf = xBase + (this.config.nozzleLength * (1 - sq) * side);
                    entities.push(this.createLine(xBase, alt + dI/2, xf, alt + dI/2, layer));
                    entities.push(this.createLine(xBase, alt - dI/2, xf, alt - dI/2, layer));
                    if (sq < 0.01) entities.push(this.createLine(xf, alt + dI/2, xf, alt - dI/2, layer));
                    entities.push(this.createUniversalEllipse(xf, alt, dI/2, sq, layer));
                    entities.push(this.createMText(xf + 40 * side, alt + dI/2 + 20, `P${index}`, 0, layer));
                }

                // VUE DE DESSUS (Rectangles de tubulure)
                const radD = ((ang - 90) * Math.PI) / 180;
                const cD = Math.cos(radD), sD = Math.sin(radD);
                const p1x = dessusX + R * cD - (dI/2 * sD), p1y = dessusY + R * sD + (dI/2 * cD);
                const p2x = dessusX + (R+100) * cD - (dI/2 * sD), p2y = dessusY + (R+100) * sD + (dI/2 * cD);
                const p3x = dessusX + (R+100) * cD + (dI/2 * sD), p3y = dessusY + (R+100) * sD - (dI/2 * cD);
                const p4x = dessusX + R * cD + (dI/2 * sD), p4y = dessusY + R * sD - (dI/2 * cD);
                
                entities.push(this.createLine(p1x, p1y, p2x, p2y, "PIQUAGES"));
                entities.push(this.createLine(p2x, p2y, p3x, p3y, "PIQUAGES"));
                entities.push(this.createLine(p3x, p3y, p4x, p4y, "PIQUAGES"));

                if (!angleRegistry[ang]) angleRegistry[ang] = 0;
                const dist = R + 180 + (angleRegistry[ang] * 70);
                entities.push(this.createMText(dessusX + dist * cD, dessusY + dist * sD, `P${index}`, 0, "PIQUAGES"));
                angleRegistry[ang]++;
            });
        }

        // --- 5. TABLEAU DES PIQUAGES ---
        this.drawTable(entities, tableauX, tableauY, specs.piquages_techniques || []);

        // --- 6. CARTOUCHE COMPLET ---
        this.drawCartouche(entities, cx, yBot, yTop, dim, specs, project);

        // --- 7. CADRE ET COTES PRINCIPALES ---
        const xMin = faceX - R - 300;
        const xMax = cx + this.config.cartWidth;
        entities.push(this.createLine(xMin, yBot, xMax, yBot, "CADRE"));
        entities.push(this.createLine(xMax, yBot, xMax, yTop, "CADRE"));
        entities.push(this.createLine(xMax, yTop, xMin, yTop, "CADRE"));
        entities.push(this.createLine(xMin, yTop, xMin, yBot, "CADRE"));
        
        entities.push(this.createMText(faceX + R + 100, Htot/2, `${Htot} mm`, 90, "COTATIONS"));
        entities.push(this.createMText(faceX, -150, `%%c ${D} mm`, 0, "COTATIONS"));

        return entities;
    },

    drawTable: function(entities, x, y, piquages) {
        const colW = [80, 100, 120, 700], rowH = 80;
        let curY = y;
        
        const drawRow = (data, isHeader) => {
            let tx = x;
            entities.push(this.createLine(x, curY, x + 1000, curY, "TABLEAU"));
            colW.forEach((w, i) => {
                if (Array.isArray(data[i])) {
                    entities.push(this.createMText(tx + 10, curY - 25, data[i][0] || "", 0, "TABLEAU"));
                    entities.push(this.createMText(tx + 10, curY - 55, data[i][1] || "", 0, "TABLEAU"));
                } else {
                    entities.push(this.createMText(tx + 10, curY - 40, data[i], 0, "TABLEAU"));
                }
                entities.push(this.createLine(tx, curY, tx, curY - rowH, "TABLEAU"));
                tx += w;
            });
            entities.push(this.createLine(tx, curY, tx, curY - rowH, "TABLEAU"));
            entities.push(this.createLine(x, curY - rowH, x + 1000, curY - rowH, "TABLEAU"));
            curY -= rowH;
        };

        drawRow(["Pos", "Elev", "Ang", "Description"], true);
        piquages.slice(0, 15).forEach((p, i) => {
            let desc = (p.Description_Complete || p.Usage_piquage || "").replace(/"/g, "''");
            let lines = ["", ""];
            if (desc.length > 60) {
                let cut = desc.lastIndexOf(' ', 60);
                lines = [desc.substring(0, cut), desc.substring(cut).trim()];
            } else { lines = [desc, ""]; }
            drawRow([`P${i}`, p.Elevation_mm, `${p.Angle_degres}%%d`, lines], false);
        });
    },

    drawCartouche: function(entities, cx, yBot, yTop, dim, specs, project) {
        const cw = 1000;
        const isQ = project.isQotation == "1";
        const clean = (t) => (t || "---").replace(/"/g, "''");

        // Structure
        entities.push(this.createLine(cx, yBot, cx, yTop, "CARTOUCHE"));
        entities.push(this.createLine(cx + cw, yBot, cx + cw, yTop, "CARTOUCHE"));
        
        // Entête
        entities.push(this.createMText(cx + 20, yTop - 100, "ISPAG - VAULRUZ", 0, "CARTOUCHE"));
        entities.push(this.createLine(cx, yTop - 250, cx + cw, yTop - 250, "CARTOUCHE"));
        
        // Client et Objet
        entities.push(this.createMText(cx + 20, yTop - 300, `CLIENT: ${isQ ? "--- (OFFRE)" : clean(project.nom_entreprise)}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yTop - 380, `OBJET: ${clean(project.ObjetCommande).substring(0, 60)}`, 0, "CARTOUCHE"));
        
        // Données Techniques
        const ty = yTop - 450;
        entities.push(this.createLine(cx, ty, cx + cw, ty, "CARTOUCHE"));
        const pS = parseFloat(dim.Pression_Max_bar || 3).toFixed(1);

        entities.push(this.createMText(cx + 20, ty - 60,  `N%%d DESSIN: ${specs.tank_id || '---'}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 120, `N%%d PROJET: ${(!isQ && project.NumCommande) ? project.NumCommande : '---'}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 180, `MATERIAU: ${dim.Matiere || "S235JR"}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 240, `VOLUME: ${dim.Volume_L || "---"} L`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 300, `TEMP. MAX: ${dim.Temperature_Max || "109"}%%dC`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 360, `PRESS. S/E: ${pS} / ${(pS * 1.5).toFixed(1)} bar`, 0, "CARTOUCHE"));

        // Séparateur Isolation
        entities.push(this.createLine(cx, yTop - 1000, cx + cw, yTop - 1000, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yTop - 1050, "ISOLATION: ---", 0, "CARTOUCHE"));

        // Pied de page
        entities.push(this.createLine(cx, yBot + 150, cx + cw, yBot + 150, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yBot + 100, `DATE: ${new Date().toLocaleDateString()}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yBot + 40, `REF: ${specs.Designed_by || 'C. Barthel'}`, 0, "CARTOUCHE"));
    },

    createLine: function(x1, y1, x2, y2, layer) {
        return { type: "LINE", layer, color: this.config.layers[layer].color, start: {x: x1, y: y1}, end: {x: x2, y: y2} };
    },
    createEllipse: function(cx, cy, rx, ratio, s, e, layer) {
        return { type: "ELLIPSE", layer, color: this.config.layers[layer].color, center: {x: cx, y: cy}, major_axis: {x: rx, y: 0}, ratio: ratio, start_param: s, end_param: e };
    },
    createUniversalEllipse: function(cx, cy, rV, sq, layer) {
        return { type: "ELLIPSE", layer, color: this.config.layers[layer].color, center: {x: cx, y: cy}, major_axis: {x: 0, y: rV}, ratio: Math.max(0.001, sq), start_param: 0, end_param: 6.283 };
    },
    createMText: function(x, y, text, rot, layer) {
        return { type: "MTEXT", layer, color: this.config.layers[layer].color, point: {x, y}, height: this.config.textHeight, text: text.toString(), rotation: rot };
    }
};