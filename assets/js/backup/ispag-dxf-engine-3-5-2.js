/**
 * ISPAG DXF ENGINE
 * Version 3.5.2
 * - Volume : Récupéré via tankSpecs.dimensions_principales.Volume_L
 * - Condition Deal ID : Affiché uniquement si NumCommande existe ET isQotation != 1
 * - Nettoyage cartouche : "ISPAG - VAULRUZ"
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

    generateEntities: function(specs, project = {}) {
        const D = parseFloat(specs.dimensions_principales.Diametre_mm);
        const Htot = parseFloat(specs.dimensions_principales.Hauteur_mm);
        const GC = parseFloat(specs.dimensions_principales.Ground_clearance || 50);
        const f = parseFloat(specs.dimensions_principales.Bottom_Height_mm || 280);
        const supportType = (specs.dimensions_principales.Support || '').toLowerCase();
        
        const R = D / 2;
        const ratioFonds = f / R;
        let entities = [];

        // 1. STRUCTURE FACE
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

        // 3. VUE DE DESSUS (0° Bas)
        const vdx = R + 1200; 
        const vdy = Htot / 2;
        for (let a = 0; a < 360; a += 15) {
            const rad = ((a - 90) * Math.PI) / 180;
            entities.push(this.createLine(vdx + (R-50)*Math.cos(rad), vdy + (R-50)*Math.sin(rad), vdx + R*Math.cos(rad), vdy + R*Math.sin(rad), "COTATIONS"));
        }
        entities.push(this.createUniversalEllipse(vdx, vdy, R, 1, "VIROLE"));

        // 4. PIQUAGES
        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            const angleRegistry = {};
            specs.piquages_techniques.forEach((p, index) => {
                const angleDeg = parseFloat(p.Angle_degres || 0);
                const radRepere = ((angleDeg - 90) * Math.PI) / 180;
                const alt = parseFloat(p.Elevation_mm || 0);
                const dInt = parseFloat(p.Bride_Int_mm || 50);
                const dExt = parseFloat(p.Bride_Ext_mm || dInt);
                
                const sinFace = Math.sin((angleDeg * Math.PI) / 180);
                const cosFace = Math.cos((angleDeg * Math.PI) / 180);
                const xBase = R * sinFace;
                const layer = cosFace >= 0 ? "PIQUAGES" : "PIQUAGES_ARRIERE";
                const side = sinFace >= 0 ? 1 : -1;
                const squash = Math.abs(cosFace);
                const L_eff = (Math.abs(angleDeg % 180) === 0) ? 0 : this.config.nozzleLength * (1 - squash);
                const xFace = xBase + (L_eff * side);

                entities.push(this.createLine(xBase, alt + dInt/2, xFace, alt + dInt/2, layer));
                entities.push(this.createLine(xBase, alt - dInt/2, xFace, alt - dInt/2, layer));
                if (Math.abs(angleDeg % 180) === 90) entities.push(this.createLine(xFace, alt + dInt/2, xFace, alt - dInt/2, layer));
                entities.push(this.createUniversalEllipse(xFace, alt, dInt/2, squash, layer));
                if (parseInt(p.Type_raccord) === 13 || parseInt(p.Type_raccord) === 24) entities.push(this.createUniversalEllipse(xFace, alt, dExt/2, squash, layer));
                entities.push(this.createMText(xFace + (35 * side), alt + (dExt/2 + 40), `P${index}`, 0, layer));

                if (angleRegistry[angleDeg] === undefined) angleRegistry[angleDeg] = 0;
                const textDist = R + 110 + (angleRegistry[angleDeg] * 70);
                angleRegistry[angleDeg]++;
                entities.push(this.createLine(vdx + R*Math.cos(radRepere), vdy + R*Math.sin(radRepere), vdx + (R+80)*Math.cos(radRepere), vdy + (R+80)*Math.sin(radRepere), "PIQUAGES"));
                entities.push(this.createMText(vdx + textDist*Math.cos(radRepere), vdy + textDist*Math.sin(radRepere), `P${index}`, 0, "PIQUAGES"));
            });
        }

        // 5. TABLEAU
        const tx = -R - 1000;
        entities.push(this.createMText(tx, Htot + 50, "Pos. | Elev. | Ang. | Description", 0, "TABLEAU"));
        entities.push(this.createLine(tx, Htot + 30, tx + 750, Htot + 30, "TABLEAU"));
        if (specs.piquages_techniques) {
            specs.piquages_techniques.forEach((p, i) => {
                const y = Htot - (i * 60);
                entities.push(this.createMText(tx, y, `P${i} | ${p.Elevation_mm} | ${p.Angle_degres}%%d | DN${p.Bride_Int_mm} - ${p.Description || ''}`, 0, "TABLEAU"));
                entities.push(this.createLine(tx, y - 15, tx + 750, y - 15, "TABLEAU"));
            });
        }

        // 6. CARTOUCHE
        const cx = vdx + R + 150;
        const isOffre = (project.isQotation == "1");
        
        // Volume depuis les specs
        const volumeL = specs.dimensions_principales.Volume_L || "---";

        // Condition Deal ID : seulement si NumCommande et pas une offre
        let displayDeal = "---";
        if (project.NumCommande && !isOffre) {
            displayDeal = project.NumCommande ;
        }

        const displayClient = isOffre ? "--- (OFFRE)" : (project.nom_entreprise || "---").replace(/\r\n|\r|\n/g, " ");
        const objet = project.ObjetCommande || "---";

        entities.push(this.createLine(cx, 0, cx + 900, 0, "CARTOUCHE"));
        entities.push(this.createLine(cx, 500, cx + 900, 500, "CARTOUCHE"));
        entities.push(this.createLine(cx, 0, cx, 500, "CARTOUCHE"));
        entities.push(this.createLine(cx + 900, 0, cx + 900, 500, "CARTOUCHE"));

        entities.push(this.createMText(cx + 20, 440, "ISPAG - VAULRUZ", 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 360, `CLIENT: ${displayClient.substring(0, 45)}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 280, `OBJET: ${objet.substring(0, 45)}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 200, `Num DE PROJET: ${displayDeal} / Pos: ${specs.N_dessin || '1'}`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 120, `VOL: ${volumeL} L`, 0, "CARTOUCHE"));
        entities.push(this.createMText(cx + 20, 40, `DATE: ${new Date().toLocaleDateString()} | Ref: ${project.contact_name || 'C. Barthel'}`, 0, "CARTOUCHE"));

        // 7. CADRE & COTES
        const xMin = tx - 150; const xMax = cx + 950;
        entities.push(this.createLine(xMin, -450, xMax, -450, "CADRE"));
        entities.push(this.createLine(xMax, -450, xMax, Htot + 450, "CADRE"));
        entities.push(this.createLine(xMax, Htot + 450, xMin, Htot + 450, "CADRE"));
        entities.push(this.createLine(xMin, Htot + 450, xMin, -450, "CADRE"));
        entities.push(this.createMText(R + 250, Htot/2, `${Htot} mm`, 90, "COTATIONS"));
        entities.push(this.createMText(0, -150, `${D} mm`, 0, "COTATIONS"));

        return entities;
    },

    createLine: (x1, y1, x2, y2, layer) => ({ type: "LINE", layer, color: IspagDxfEngine.config.layers[layer].color, start: {x: x1, y: y1}, end: {x: x2, y: y2} }),
    createEllipse: (cx, cy, rx, ratio, s, e, layer) => ({ type: "ELLIPSE", layer, color: IspagDxfEngine.config.layers[layer].color, center: {x: cx, y: cy}, major_axis: {x: rx, y: 0}, ratio: ratio, start_param: s, end_param: e }),
    createUniversalEllipse: function(cx, cy, rVert, squash, layer) {
        return { type: "ELLIPSE", layer, color: this.config.layers[layer].color, center: {x: cx, y: cy}, major_axis: {x: 0, y: rVert}, ratio: Math.max(0.001, squash), start_param: 0, end_param: 6.28318 };
    },
    createMText: (x, y, text, rotation, layer = "COTATIONS") => ({ type: "MTEXT", layer, color: IspagDxfEngine.config.layers[layer].color, point: {x, y}, height: 25, text, rotation })
};