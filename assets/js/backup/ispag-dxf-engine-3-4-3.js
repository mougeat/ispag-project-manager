/**
 * ISPAG DXF ENGINE
 * Version 3.4.3 - DÉCALAGE TEXTE SEUL (TRAITS FIXES)
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
        nozzleLength: 100 
    },

    generateEntities: function(specs) {
        const D = parseFloat(specs.dimensions_principales.Diametre_mm);
        const Htot = parseFloat(specs.dimensions_principales.Hauteur_mm);
        const GC = parseFloat(specs.dimensions_principales.Ground_clearance || 50);
        const f = parseFloat(specs.dimensions_principales.Bottom_Height_mm || 280);
        const supportType = (specs.dimensions_principales.Support || '').toLowerCase();
        
        const R = D / 2;
        const ratioFonds = f / R;
        let entities = [];

        // 1. STRUCTURE
        entities.push(this.createEllipse(0, GC + f, R, ratioFonds, Math.PI, 2 * Math.PI, "FONDS"));
        entities.push(this.createEllipse(0, Htot - f, R, ratioFonds, 0, Math.PI, "FONDS"));
        entities.push(this.createLine(-R, GC + f, -R, Htot - f, "VIROLE"));
        entities.push(this.createLine(R, GC + f, R, Htot - f, "VIROLE"));

        // 2. SUPPORTS
        if (supportType.includes('ring') || supportType.includes('virole')) {
            const Rv = R - 100;
            const dy = Math.sqrt(Math.pow(f, 2) * (1 - Math.pow(Rv, 2) / Math.pow(R, 2)));
            const yContact = (GC + f) - dy;
            entities.push(this.createLine(-Rv, 0, Rv, 0, "SUPPORTS"));
            entities.push(this.createLine(-Rv, yContact, Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(-Rv, 0, -Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(Rv, 0, Rv, yContact, "SUPPORTS"));
        }

        // 3. PIQUAGES & VUE DE DESSUS
        const vdx = R + 1200; 
        const vdy = Htot / 2;

        for (let a = 0; a < 360; a += 15) {
            const rad = (a * Math.PI) / 180;
            entities.push(this.createLine(vdx, vdy, vdx + R * Math.sin(rad), vdy + R * Math.cos(rad), "COTATIONS"));
        }
        entities.push(this.createUniversalEllipse(vdx, vdy, R, 1, "VIROLE"));

        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            const angleRegistry = {};

            specs.piquages_techniques.forEach((p, index) => {
                const angleDeg = parseFloat(p.Angle_degres || 0);
                const angleRad = (angleDeg * Math.PI) / 180;
                const alt = parseFloat(p.Elevation_mm || 0);
                const dInt = parseFloat(p.Bride_Int_mm || 50);
                const dExt = parseFloat(p.Bride_Ext_mm || dInt);
                const type = parseInt(p.Type_raccord);
                
                const sinA = Math.sin(angleRad);
                const cosA = Math.cos(angleRad);
                const squash = Math.abs(cosA);
                const side = sinA >= 0 ? 1 : -1;
                const isFacePure = (Math.abs(angleDeg % 180) === 0);
                const isSidePure = (Math.abs(angleDeg % 180) === 90);
                const L_eff = isFacePure ? 0 : this.config.nozzleLength * (1 - squash);
                const xFace = (R * sinA) + (L_eff * side);
                const layer = cosA >= 0 ? "PIQUAGES" : "PIQUAGES_ARRIERE";

                // VUE FACE
                if (!isFacePure) {
                    entities.push(this.createLine(R * sinA, alt + dInt/2, xFace, alt + dInt/2, layer));
                    entities.push(this.createLine(R * sinA, alt - dInt/2, xFace, alt - dInt/2, layer));
                    if (isSidePure) entities.push(this.createLine(xFace, alt + dInt/2, xFace, alt - dInt/2, layer));
                }
                entities.push(this.createUniversalEllipse(xFace, alt, dInt/2, squash, layer));
                if (type === 13 || type === 24) {
                    entities.push(this.createUniversalEllipse(xFace, alt, dExt/2, squash, layer));
                }
                entities.push(this.createMText(xFace + (30 * side), alt + (dExt/2 + 40), `P${index}`, 0, layer));

                // VUE DESSUS
                if (angleRegistry[angleDeg] === undefined) angleRegistry[angleDeg] = 0;
                
                // Trait fixe : toujours R + 80
                const lineEndDist = R + 80;
                // Texte décalé : augmente de 60mm à chaque doublon sur le même angle
                const textDist = R + 100 + (angleRegistry[angleDeg] * 60);
                angleRegistry[angleDeg]++;

                const xLineEnd = vdx + lineEndDist * sinA;
                const yLineEnd = vdy + lineEndDist * cosA;
                const xText = vdx + textDist * sinA;
                const yText = vdy + textDist * cosA;
                
                entities.push(this.createLine(vdx + R * sinA, vdy + R * cosA, xLineEnd, yLineEnd, "PIQUAGES"));
                entities.push(this.createMText(xText, yText, `P${index}`, 0, "PIQUAGES"));
            });
        }

        // 4. TABLEAU NOMENCLATURE
        const tx = -R - 1000;
        const colW = [80, 150, 120, 350];
        const rowH = 60;
        entities.push(this.createMText(tx + 5, Htot + 50, "Pos. | Elev. | Ang. | Description", 0, "TABLEAU"));
        entities.push(this.createLine(tx, Htot + 30, tx + 700, Htot + 30, "TABLEAU"));
        
        if (specs.piquages_techniques) {
            specs.piquages_techniques.forEach((p, i) => {
                const y = Htot - (i * rowH);
                entities.push(this.createMText(tx + 5, y, `P${i}`, 0, "TABLEAU"));
                entities.push(this.createMText(tx + colW[0], y, `${p.Elevation_mm}`, 0, "TABLEAU"));
                entities.push(this.createMText(tx + colW[0] + colW[1], y, `${p.Angle_degres}%%d`, 0, "TABLEAU"));
                entities.push(this.createMText(tx + colW[0] + colW[1] + colW[2], y, `DN${p.Bride_Int_mm} - ${p.Description || 'Raccord'}`, 0, "TABLEAU"));
                entities.push(this.createLine(tx, y - 15, tx + 700, y - 15, "TABLEAU"));
            });
        }

        // 5. CARTOUCHE
        const cx = vdx + R + 100;
        entities.push(this.createLine(cx, 0, cx + 800, 0, "CARTOUCHE"));
        entities.push(this.createLine(cx, 450, cx + 800, 450, "CARTOUCHE"));
        entities.push(this.createLine(cx, 0, cx, 450, "CARTOUCHE"));
        entities.push(this.createLine(cx + 800, 0, cx + 800, 450, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 380, "ISPAG AG - VAULRUZ", 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 300, `Dessin: ${specs.N_dessin || '---'}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 220, `Contenu: ${Math.round(Math.PI * Math.pow(R/100, 2) * (Htot/10))} Litres`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 140, `Client: ${specs.Client || '---'}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 60, `Date: ${new Date().toLocaleDateString()}`, 0, "CARTOUCHE"));

        // 6. CADRE & COTATIONS
        const xMin = tx - 100;
        const xMax = cx + 800 + 100;
        entities.push(this.createLine(xMin, -400, xMax, -400, "CADRE"));
        entities.push(this.createLine(xMax, -400, xMax, Htot + 400, "CADRE"));
        entities.push(this.createLine(xMax, Htot + 400, xMin, Htot + 400, "CADRE"));
        entities.push(this.createLine(xMin, Htot + 400, xMin, -400, "CADRE"));
        
        entities.push(this.createMText(R + 250, Htot / 2, `${Htot} mm`, 90));
        entities.push(this.createMText(0, -150, `${D} mm`, 0));

        return entities;
    },

    createLine: (x1, y1, x2, y2, layer) => ({ type: "LINE", layer, color: IspagDxfEngine.config.layers[layer].color, start: {x: x1, y: y1}, end: {x: x2, y: y2} }),
    createEllipse: (cx, cy, rx, ratio, s, e, layer) => ({ type: "ELLIPSE", layer, color: IspagDxfEngine.config.layers[layer].color, center: {x: cx, y: cy}, major_axis: {x: rx, y: 0}, ratio: ratio, start_param: s, end_param: e }),
    createUniversalEllipse: function(cx, cy, rVert, squash, layer) {
        return { type: "ELLIPSE", layer, color: this.config.layers[layer].color, center: {x: cx, y: cy}, major_axis: {x: 0, y: rVert}, ratio: Math.max(0.001, squash), start_param: 0, end_param: 6.28318 };
    },
    createMText: (x, y, text, rotation, layer = "COTATIONS") => ({ type: "MTEXT", layer, color: IspagDxfEngine.config.layers[layer].color, point: {x, y}, height: 25, text, rotation })
};