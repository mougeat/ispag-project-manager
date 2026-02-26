/**
 * ISPAG DXF ENGINE - Version 6.3.2
 * - ÉTAT : VERSION COMPLÈTE (Full Source)
 * - LOGIQUE : 
 * 1. Tube < milieu : coudé bas
 * 2. Tube > milieu : coudé haut
 * 3. Tube sous fond bas : coudé remonte vers fond
 * 4. Piquage sommet : vertical (purge)
 * - RENDU : Police Arial, Vue de dessus centrée entre cuve et cartouche.
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
            CADRE: { color: 250 },
            SOUDURES: { color: 1 }, 
            INTERNES: { color: 252, lineType: "DASHED" } 
        },
        textHeight: 28,
        nozzleLength: 100,
        cartWidth: 1000
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

        // --- 1. LIMITES & CALCUL DU CENTRAGE ---
        const yTop = Htot + 500;
        const yBot = -500;
        const faceX = 0;
        const xMin = -800;
        const drawingWidth = (yTop - yBot) * 1.6;
        const xMax = xMin + drawingWidth;
        const cx = xMax - this.config.cartWidth;

        // Centrage horizontal de la vue de dessus entre la cuve et le cartouche
        const spaceStart = faceX + R;
        const spaceEnd = cx;
        const dessusX = (spaceStart + spaceEnd) / 2;
        const dessusY = (yBot + yTop) / 2; 

        // --- 2. CUVE (PAGE 1) ---
        entities.push(this.createEllipse(faceX, GC + f, R, f/R, Math.PI, 2 * Math.PI, "FONDS"));
        entities.push(this.createEllipse(faceX, Htot - f, R, f/R, 0, Math.PI, "FONDS"));
        entities.push(this.createLine(faceX - R, GC + f, faceX - R, Htot - f, "VIROLE"));
        entities.push(this.createLine(faceX + R, GC + f, faceX + R, Htot - f, "VIROLE"));

        // --- 3. PIQUAGES & LOGIQUE TUBES ---
        const angleRegistry = {};
        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            specs.piquages_techniques.forEach((p, index) => {
                const descFull = (p.Description_Complete || "").toLowerCase();
                const labelFull = (p.Type_raccord_label || "").toLowerCase();
                const isWeld = descFull.includes("weld") || descFull.includes("soudure") || labelFull.includes("weld");
                
                if (isWeld || descFull.includes("plate") || descFull.includes("tôle")) return;

                const ang = parseFloat(p.Angle_degres || 0);
                const alt = parseFloat(p.Elevation_mm || 0);
                const dI = parseFloat(p.Bride_Int_mm || 50);
                const radF = (ang * Math.PI) / 180;
                const cosF = Math.cos(radF);
                const sinF = Math.sin(radF);
                const layer = cosF >= -0.001 ? "PIQUAGES" : "PIQUAGES_ARRIERE";

                // DESSIN EXTERNE
                if (alt >= Htot - 10) {
                    // Purge verticale
                    const xp = faceX + (R * sinF * 0.4);
                    entities.push(this.createLine(xp - dI/2, Htot, xp - dI/2, Htot + 100, layer));
                    entities.push(this.createLine(xp + dI/2, Htot, xp + dI/2, Htot + 100, layer));
                    entities.push(this.createLine(xp - dI/2, Htot + 100, xp + dI/2, Htot + 100, layer));
                    entities.push(this.createMText(xp, Htot + 130, `P${index}`, 0, layer));
                } else {
                    // Piquage latéral
                    const xBase = faceX + (R * sinF);
                    const sq = Math.abs(cosF);
                    const side = sinF >= 0 ? 1 : -1;
                    const xf = xBase + (this.config.nozzleLength * (1 - sq) * side);
                    entities.push(this.createLine(xBase, alt + dI/2, xf, alt + dI/2, layer));
                    entities.push(this.createLine(xBase, alt - dI/2, xf, alt - dI/2, layer));
                    entities.push(this.createUniversalEllipse(xf, alt, dI/2, sq, layer));
                    entities.push(this.createMText(xf + 45 * side, alt + 10, `P${index}`, 0, layer));
                }

                // DESSIN INTERNE (LOGIQUE MÉTIER)
                if (descFull.includes("tube") || descFull.includes("plongeant") || descFull.includes("pipe")) {
                    const xpTube = faceX + (R * sinF);
                    let yTarget;

                    if (alt >= Htot - 10) {
                        yTarget = Htot / 2; // Purge descend à mi-hauteur
                    } else if (alt <= GC + f) {
                        yTarget = GC + f + 50; // Sous fond bas, remonte
                    } else if (alt < Htot / 2) {
                        yTarget = GC + f + 100; // Moitié basse, coudé bas
                    } else {
                        yTarget = Htot - f - 100; // Moitié haute, coudé haut
                    }

                    entities.push(this.createLine(xpTube - dI/2, alt, xpTube - dI/2, yTarget, "INTERNES"));
                    entities.push(this.createLine(xpTube + dI/2, alt, xpTube + dI/2, yTarget, "INTERNES"));
                    entities.push(this.createLine(xpTube - dI/2, yTarget, xpTube + dI/2, yTarget, "INTERNES"));
                }

                // VUE DE DESSUS
                const radD = ((ang - 90) * Math.PI) / 180;
                const cD = Math.cos(radD);
                const sD = Math.sin(radD);
                entities.push(this.createLine(dessusX + R * cD, dessusY + R * sD, dessusX + (R + 100) * cD, dessusY + (R + 100) * sD, "PIQUAGES"));
                if (!angleRegistry[ang]) angleRegistry[ang] = 0;
                const distTxt = R + 160 + (angleRegistry[ang] * 70);
                entities.push(this.createMText(dessusX + distTxt * cD, dessusY + distTxt * sD, `P${index}`, 0, "PIQUAGES"));
                angleRegistry[ang]++;
            });
        }

        // --- 4. SOUDURES & SUPPORTS ---
        if (specs.piquages_techniques) {
            specs.piquages_techniques.forEach(p => {
                const desc = (p.Description_Complete || "").toLowerCase();
                if (desc.includes("weld") || desc.includes("soudure")) {
                    const hP = parseFloat(p.Elevation_mm);
                    entities.push(this.createLine(faceX - R, hP, faceX + R, hP, "SOUDURES"));
                }
            });
        }
        if (supportType.includes('ring') || supportType.includes('virole')) {
            const Rv = R - 50;
            const dy = Math.sqrt(Math.pow(f, 2) * (1 - Math.pow(Rv, 2) / Math.pow(R, 2)));
            const yContact = (GC + f) - dy;
            entities.push(this.createLine(faceX - Rv, 0, faceX - Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(faceX + Rv, 0, faceX + Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(faceX - Rv, 0, faceX + Rv, 0, "SUPPORTS"));
        }

        // --- 5. FINITION PAGES ---
        entities.push(this.createUniversalEllipse(dessusX, dessusY, R, 1, "VIROLE"));
        this.drawCartouche(entities, cx, yBot, yTop, dim, specs, project, "1/2");
        this.drawFrame(entities, xMin, xMax, yBot, yTop);
        entities.push(this.createMText(faceX + R + 100, Htot/2, `${Htot} mm`, 90, "COTATIONS"));
        entities.push(this.createMText(faceX, -150, `%%c ${D} mm`, 0, "COTATIONS"));

        const p2Offset = xMax + 500; 
        const p2_xMax = p2Offset + drawingWidth;
        const p2_cx = p2_xMax - this.config.cartWidth;
        this.drawFrame(entities, p2Offset, p2_xMax, yBot, yTop);
        this.drawCartouche(entities, p2_cx, yBot, yTop, dim, specs, project, "2/2");
        const tableX = p2Offset + (drawingWidth - 1800) / 2;
        this.drawTable(entities, tableX, yTop - 100, specs.piquages_techniques || []);

        return entities;
    },

    drawFrame: function(entities, x1, x2, y1, y2) {
        entities.push(this.createLine(x1, y1, x2, y1, "CADRE"));
        entities.push(this.createLine(x2, y1, x2, y2, "CADRE"));
        entities.push(this.createLine(x2, y2, x1, y2, "CADRE"));
        entities.push(this.createLine(x1, y2, x1, y1, "CADRE"));
    },

    drawTable: function(entities, x, y, piquages) {
        const colW = [100, 100, 100, 1500]; 
        const rowH = 80;
        let curY = y;
        this.drawTableRow(entities, x, curY, colW, rowH, ["POS", "ELEV.", "ANGLE", "DESCRIPTION"]);
        const filtered = piquages.filter(p => {
            const lbl = (p.Description_Complete || "").toLowerCase();
            return !lbl.includes("weld") && !lbl.includes("soudure") && !lbl.includes("plate");
        });
        filtered.forEach((p, i) => {
            curY -= rowH;
            let desc = (p.Description_Complete || p.Usage_piquage || "").replace(/"/g, "''");
            let lines = desc.length > 120 ? [desc.substring(0, 120), desc.substring(120, 240)] : [desc, ""];
            this.drawTableRow(entities, x, curY, colW, rowH, [`P${i}`, p.Elevation_mm, `${p.Angle_degres}%%d`, lines]);
        });
    },

    drawTableRow: function(entities, x, y, cols, h, data) {
        let tx = x;
        const totalW = cols.reduce((a, b) => a + b, 0);
        entities.push(this.createLine(x, y, x + totalW, y, "TABLEAU"));
        cols.forEach((w, i) => {
            const content = data[i];
            if (Array.isArray(content)) {
                entities.push(this.createMText(tx + 15, y - 25, content[0], 0, "TABLEAU"));
                entities.push(this.createMText(tx + 15, y - 55, content[1] || "", 0, "TABLEAU"));
            } else {
                entities.push(this.createMText(tx + 15, y - 45, content, 0, "TABLEAU"));
            }
            entities.push(this.createLine(tx, y, tx, y - h, "TABLEAU"));
            tx += w;
        });
        entities.push(this.createLine(tx, y, tx, y - h, "TABLEAU"));
        entities.push(this.createLine(x, y - h, x + totalW, y - h, "TABLEAU"));
    },

    drawCartouche: function(entities, cx, yBot, yTop, dim, specs, project, folio) {
        const cw = 1000;
        this.drawFrame(entities, cx, cx + cw, yBot, yTop);
        this.drawIspagLogo(entities, cx + 50, yTop - 180, 1.2);
        entities.push(this.createLine(cx, yTop - 250, cx + cw, yTop - 250, "CARTOUCHE"));
        entities.push(this.createMText(cx + 25, yTop - 310, `CLIENT: ${project.nom_entreprise || '---'}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 25, yTop - 390, `OBJET: ${(project.ObjetCommande || '---').substring(0, 50)}`, 0, "CARTOUCHE"));
        const ty = yTop - 450;
        entities.push(this.createLine(cx, ty, cx + cw, ty, "CARTOUCHE"));
        entities.push(this.createMText(cx + 25, ty - 60,  `PLAN N%%d: ${specs.tank_id}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 25, ty - 140, `MATERIAU: ${dim.Matiere || '---'}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 25, ty - 210, `VOLUME: ${dim.Volume_L || '---'} L`, 0, "CARTOUCHE"));
        entities.push(this.createLine(cx, yBot + 120, cx + cw, yBot + 120, "CARTOUCHE"));
        entities.push(this.createMText(cx + 25, yBot + 75, `DATE: ${new Date().toLocaleDateString()}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 25, yBot + 30, `DESSIN: C. Barthel | FOLIO ${folio}`, 0, "CARTOUCHE"));
    },

    drawIspagLogo: function(entities, x, y, scale = 1.0) {
        const paths = [[[0,0],[0,100]], [[30,0],[80,0],[80,50],[30,50],[30,100],[80,100]], [[110,0],[110,100],[160,100],[160,50],[110,50]], [[190,0],[215,100],[240,0]], [[200,40],[230,40]], [[320,50],[320,0],[270,0],[270,100],[320,100],[320,80]]];
        paths.forEach(p => {
            for(let i=0; i<p.length-1; i++) {
                entities.push(this.createLine(x+p[i][0]*scale, y+p[i][1]*scale, x+p[i+1][0]*scale, y+p[i+1][1]*scale, "CARTOUCHE"));
            }
        });
    },

    createLine: function(x1, y1, x2, y2, layer) {
        return { type: "LINE", layer, color: (this.config.layers[layer] || {color:250}).color, start: {x: x1, y: y1}, end: {x: x2, y: y2} };
    },
    createEllipse: function(cx, cy, rx, ratio, s, e, layer) {
        return { type: "ELLIPSE", layer, color: (this.config.layers[layer] || {color:250}).color, center: {x: cx, y: cy}, major_axis: {x: rx, y: 0}, ratio: ratio, start_param: s, end_param: e };
    },
    createUniversalEllipse: function(cx, cy, rV, sq, layer) {
        return { type: "ELLIPSE", layer, color: (this.config.layers[layer] || {color:250}).color, center: {x: cx, y: cy}, major_axis: {x: 0, y: rV}, ratio: Math.max(0.001, sq), start_param: 0, end_param: 6.283 };
    },
    createMText: function(x, y, text, rot, layer) {
        return { type: "MTEXT", layer, color: (this.config.layers[layer] || {color:250}).color, point: {x, y}, height: this.config.textHeight, text: text.toString(), rotation: rot, style: "ARIAL" };
    }
};