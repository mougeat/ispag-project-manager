/**
 * ISPAG DXF ENGINE - Version 4.8.1
 * - FIX : Détection stricte des tôles et soudures pour éviter les affichages fantômes
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

        // --- 1. LIMITES ---
        const yTop = Htot + 500;
        const yBot = -500;
        const faceX = 0;
        const xMin = -800;
        const drawingWidth = (yTop - yBot) * 1.6;
        const xMax = xMin + drawingWidth;
        const column2X = Math.max(R + 600, 1000); 
        const tableauY = Htot; 
        const dessusX = column2X + 450;
        const dessusY = 600;
        const cx = xMax - this.config.cartWidth;

        // --- 2. CUVE ---
        entities.push(this.createEllipse(faceX, GC + f, R, f/R, Math.PI, 2 * Math.PI, "FONDS"));
        entities.push(this.createEllipse(faceX, Htot - f, R, f/R, 0, Math.PI, "FONDS"));
        entities.push(this.createLine(faceX - R, GC + f, faceX - R, Htot - f, "VIROLE"));
        entities.push(this.createLine(faceX + R, GC + f, faceX + R, Htot - f, "VIROLE"));

        // --- 3. SOUDURES & INTERNES ---
        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            specs.piquages_techniques.forEach(p => {
                const label = (p.Type_raccord_label || p.Description_Complete || "").toString().toLowerCase();
                const hP = parseFloat(p.Elevation_mm);

                if (!isNaN(hP)) {
                    // On ne garde QUE les soudures. La détection des tôles est supprimée ici.
                    if (label.includes("weld") || label.includes("soudure")) {
                        entities.push(this.createLine(faceX - R, hP, faceX + R, hP, "SOUDURES"));
                        entities.push(this.createLine(faceX + R, hP, faceX + R + 150, hP, "SOUDURES"));
                        entities.push(this.createMText(faceX + R + 160, hP + 10, "SOUDURE", 0, "SOUDURES"));
                    }
                }
            });
        }

        // Support
        if (supportType.includes('ring') || supportType.includes('virole')) {
            const Rv = R - 50;
            const dy = Math.sqrt(Math.pow(f, 2) * (1 - Math.pow(Rv, 2) / Math.pow(R, 2)));
            const yContact = (GC + f) - dy;
            entities.push(this.createLine(faceX - Rv, 0, faceX - Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(faceX + Rv, 0, faceX + Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(faceX - Rv, 0, faceX + Rv, 0, "SUPPORTS"));
        }

        // --- 4. VUE DE DESSUS ---
        entities.push(this.createUniversalEllipse(dessusX, dessusY, R, 1, "VIROLE"));

        // --- 5. PIQUAGES (AVEC EXCLUSION) ---
        const angleRegistry = {};
        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            specs.piquages_techniques.forEach((p, index) => {
                const label = (p.Type_raccord_label || p.Description_Complete || "").toString().toLowerCase();
                
                // Si c'est une soudure ou une tôle, on sort du loop pour ce raccord
                if (label.includes("weld") || label.includes("soudure") || 
                    label.includes("plate") || label.includes("tôle") || 
                    label.includes("stratif")) {
                    return; 
                }

                const ang = parseFloat(p.Angle_degres || 0);
                const alt = parseFloat(p.Elevation_mm || 0);
                const dI = parseFloat(p.Bride_Int_mm || 50);
                const radF = (ang * Math.PI) / 180;
                const cosF = Math.cos(radF);
                const sinF = Math.sin(radF);
                const layer = cosF >= -0.001 ? "PIQUAGES" : "PIQUAGES_ARRIERE";

                // Face
                if (alt >= Htot - 10) {
                    const xp = faceX + (R * sinF * 0.4);
                    entities.push(this.createLine(xp - dI/2, Htot, xp - dI/2, Htot + 100, layer));
                    entities.push(this.createLine(xp + dI/2, Htot, xp + dI/2, Htot + 100, layer));
                    entities.push(this.createLine(xp - dI/2, Htot + 100, xp + dI/2, Htot + 100, layer));
                    entities.push(this.createMText(xp, Htot + 130, `P${index}`, 0, layer));
                } else {
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

                // Dessus
                const radD = ((ang - 90) * Math.PI) / 180;
                const cD = Math.cos(radD);
                const sD = Math.sin(radD);
                entities.push(this.createLine(dessusX + R * cD - (dI/2 * sD), dessusY + R * sD + (dI/2 * cD), dessusX + (R + 100) * cD - (dI/2 * sD), dessusY + (R + 100) * sD + (dI/2 * cD), "PIQUAGES"));
                entities.push(this.createLine(dessusX + R * cD + (dI/2 * sD), dessusY + R * sD - (dI/2 * cD), dessusX + (R + 100) * cD + (dI/2 * sD), dessusY + (R + 100) * sD - (dI/2 * cD), "PIQUAGES"));

                if (!angleRegistry[ang]) angleRegistry[ang] = 0;
                const distTxt = R + 180 + (angleRegistry[ang] * 70);
                entities.push(this.createMText(dessusX + distTxt * cD, dessusY + distTxt * sD, `P${index}`, 0, "PIQUAGES"));
                angleRegistry[ang]++;
            });
        }

        // --- 6. AUTRES ---
        this.drawTable(entities, column2X, tableauY, specs.piquages_techniques || []);
        this.drawCartouche(entities, cx, yBot, yTop, dim, specs, project);

        entities.push(this.createLine(xMin, yBot, xMax, yBot, "CADRE"));
        entities.push(this.createLine(xMax, yBot, xMax, yTop, "CADRE"));
        entities.push(this.createLine(xMax, yTop, xMin, yTop, "CADRE"));
        entities.push(this.createLine(xMin, yTop, xMin, yBot, "CADRE"));

        entities.push(this.createMText(faceX + R + 100, Htot/2, `${Htot} mm`, 90, "COTATIONS"));
        entities.push(this.createMText(faceX, -150, `%%c ${D} mm`, 0, "COTATIONS"));

        return entities;
    },

    drawTable: function(entities, x, y, piquages) {
        const colW = [80, 100, 120, 650];
        const rowH = 80;
        let curY = y;
        this.drawTableRow(entities, x, curY, colW, rowH, ["Pos", "Elev", "Ang", "Description"]);
        
        const filtered = piquages.filter(p => {
            const lbl = (p.Type_raccord_label || p.Description_Complete || "").toLowerCase();
            return !lbl.includes("weld") && !lbl.includes("soudure") && !lbl.includes("plate") && !lbl.includes("tôle");
        });

        filtered.slice(0, 15).forEach((p, i) => {
            curY -= rowH;
            let desc = (p.Description_Complete || p.Usage_piquage || "").replace(/"/g, "''");
            let lines = desc.length > 55 ? [desc.substring(0, 55), desc.substring(55, 110)] : [desc, ""];
            this.drawTableRow(entities, x, curY, colW, rowH, [`P${i}`, p.Elevation_mm, `${p.Angle_degres}%%d`, lines]);
        });
    },

    drawTableRow: function(entities, x, y, cols, h, data) {
        let tx = x;
        const totalW = 950;
        entities.push(this.createLine(x, y, x + totalW, y, "TABLEAU"));
        cols.forEach((w, i) => {
            if (Array.isArray(data[i])) {
                entities.push(this.createMText(tx + 10, y - 25, data[i][0], 0, "TABLEAU"));
                entities.push(this.createMText(tx + 10, y - 55, data[i][1], 0, "TABLEAU"));
            } else {
                entities.push(this.createMText(tx + 10, y - 40, data[i], 0, "TABLEAU"));
            }
            entities.push(this.createLine(tx, y, tx, y - h, "TABLEAU"));
            tx += w;
        });
        entities.push(this.createLine(tx, y, tx, y - h, "TABLEAU"));
        entities.push(this.createLine(x, y - h, x + totalW, y - h, "TABLEAU"));
    },

    drawCartouche: function(entities, cx, yBot, yTop, dim, specs, project) {
        const cw = 1000;
        entities.push(this.createLine(cx, yBot, cx, yTop, "CARTOUCHE"));
        entities.push(this.createLine(cx + cw, yBot, cx + cw, yTop, "CARTOUCHE"));
        this.drawIspagLogo(entities, cx + 50, yTop - 180, 1.2);
        entities.push(this.createLine(cx, yTop - 250, cx + cw, yTop - 250, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yTop - 300, `CLIENT: ${(project.nom_entreprise || '---').replace(/"/g, "''")}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yTop - 380, `OBJET: ${(project.ObjetCommande || '---').substring(0, 60)}`, 0, "CARTOUCHE"));
        const ty = yTop - 450;
        entities.push(this.createLine(cx, ty, cx + cw, ty, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 60,  `N%%d DESSIN: ${specs.tank_id}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 180, `MATERIAU: ${dim.Matiere}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, ty - 240, `VOLUME: ${dim.Volume_L} L`, 0, "CARTOUCHE"));
        entities.push(this.createLine(cx, yBot + 150, cx + cw, yBot + 150, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yBot + 100, `DATE: ${new Date().toLocaleDateString()}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, yBot + 40, `REF: C. Barthel`, 0, "CARTOUCHE"));
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
        return { type: "MTEXT", layer, color: (this.config.layers[layer] || {color:250}).color, point: {x, y}, height: this.config.textHeight, text: text.toString(), rotation: rot };
    }
};