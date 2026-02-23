/**
 * ISPAG DXF ENGINE
 * Version 2.9.8 - RESTAURATION COMPLÈTE + FIX AFFICHAGE
 */
const IspagDxfEngine = {
    
    config: {
        layers: {
            FONDS: { color: 1 },
            VIROLE: { color: 2 },
            SUPPORTS: { color: 4 },
            PIQUAGES: { color: 3 },
            PIQUAGES_ARRIERE: { color: 8 },
            COTATIONS: { color: 7 }
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

        // 1. STRUCTURE & FONDS
        entities.push(this.createEllipse(0, GC + f, R, ratioFonds, Math.PI, 2 * Math.PI, "FONDS"));
        entities.push(this.createEllipse(0, Htot - f, R, ratioFonds, 0, Math.PI, "FONDS"));
        entities.push(this.createLine(-R, GC + f, -R, Htot - f, "VIROLE"));
        entities.push(this.createLine(R, GC + f, R, Htot - f, "VIROLE"));

        // 2. SUPPORTS (Restauration complète)
        if (supportType.includes('ring') || supportType.includes('virole')) {
            const Rv = R - 100;
            const dy = Math.sqrt(Math.pow(f, 2) * (1 - Math.pow(Rv, 2) / Math.pow(R, 2)));
            const yContact = (GC + f) - dy;
            entities.push(this.createLine(-Rv, 0, Rv, 0, "SUPPORTS"));
            entities.push(this.createLine(-Rv, yContact, Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(-Rv, 0, -Rv, yContact, "SUPPORTS"));
            entities.push(this.createLine(Rv, 0, Rv, yContact, "SUPPORTS"));
        }

        // 3. PIQUAGES (Logique complète avec Brides et Trous)
        if (specs.piquages_techniques && Array.isArray(specs.piquages_techniques)) {
            specs.piquages_techniques.forEach((p, index) => {
                const angleDeg = parseFloat(p.Angle_degres || 0);
                const angleRad = (angleDeg * Math.PI) / 180;
                const alt = parseFloat(p.Elevation_mm || 0);
                const dInt = parseFloat(p.Bride_Int_mm || 50);
                const dExt = parseFloat(p.Bride_Ext_mm || dInt);
                const type = parseInt(p.Type_raccord);
                
                const xBase = R * Math.sin(angleRad);
                const cosA = Math.cos(angleRad);
                const squash = Math.abs(cosA);
                const side = Math.sin(angleRad) >= 0 ? 1 : -1;
                
                const isFacePure = (Math.abs(angleDeg % 180) === 0);
                const isSidePure = (Math.abs(angleDeg % 180) === 90);
                const L_eff = isFacePure ? 0 : this.config.nozzleLength * (1 - squash);
                const xFace = xBase + (L_eff * side);

                const layer = cosA >= 0 ? "PIQUAGES" : "PIQUAGES_ARRIERE";

                // --- TUBE ---
                if (!isFacePure) {
                    entities.push(this.createLine(xBase, alt + dInt/2, xFace, alt + dInt/2, layer));
                    entities.push(this.createLine(xBase, alt - dInt/2, xFace, alt - dInt/2, layer));
                    if (isSidePure) {
                        entities.push(this.createLine(xFace, alt + dInt/2, xFace, alt - dInt/2, layer));
                    }
                }

                // --- FACE DU PIQUAGE (Cercle ou Ellipse verticale) ---
                // On utilise createUniversalEllipse qui garantit un ratio <= 1
                entities.push(this.createUniversalEllipse(xFace, alt, dInt/2, squash, layer));
                
                // --- BRIDES (Type 13 / 24) ---
                if (type === 13 || type === 24) {
                    entities.push(this.createUniversalEllipse(xFace, alt, dExt/2, squash, layer));
                    
                    if (isSidePure) {
                        const ep = (parseFloat(p.Epaisseur_Bride_mm) || 20) * side;
                        entities.push(this.createLine(xFace, alt + dExt/2, xFace + ep, alt + dExt/2, layer));
                        entities.push(this.createLine(xFace, alt - dExt/2, xFace + ep, alt - dExt/2, layer));
                        entities.push(this.createLine(xFace + ep, alt + dExt/2, xFace + ep, alt - dExt/2, layer));
                    }

                    // TROUS DE BOULONS (Seulement si un peu de face est visible)
                    if (cosA > 0.1) {
                        const nb = parseInt(p.Bride_nb_drilling) || 0;
                        const rEnt = (dInt + dExt) / 4;
                        for (let i = 0; i < nb; i++) {
                            const a = (i * 2 * Math.PI) / nb;
                            // Pour les trous, on utilise aussi l'ellipse verticale (ratio squash)
                            entities.push(this.createUniversalEllipse(xFace + rEnt * Math.cos(a) * squash, alt + rEnt * Math.sin(a), 8, squash, layer));
                        }
                    }
                }

                // --- LABEL ---
                const offsetLabel = (dExt/2 + 40);
                entities.push(this.createMText(xFace + (30 * side), alt + offsetLabel, `P${index} (${angleDeg}°)`, 0, layer));
            });
        }

        // 4. COTATIONS (Restauration)
        entities.push(this.createMText(R + 250, Htot / 2, `${Htot} mm`, 90));
        entities.push(this.createMText(0, -150, `${D} mm`, 0));

        return entities;
    },

    // --- HELPERS COMPATIBLES ---
    createLine: (x1, y1, x2, y2, layer) => ({ 
        type: "LINE", layer, color: IspagDxfEngine.config.layers[layer].color, 
        start: {x: x1, y: y1}, end: {x: x2, y: y2} 
    }),

    createEllipse: (cx, cy, rx, ratio, s, e, layer) => ({ 
        type: "ELLIPSE", layer, color: IspagDxfEngine.config.layers[layer].color, 
        center: {x: cx, y: cy}, major_axis: {x: rx, y: 0}, ratio: ratio, start_param: s, end_param: e 
    }),

    // RUSE : Définit l'axe majeur sur Y pour que Ratio soit Squash (donc <= 1)
    createUniversalEllipse: function(cx, cy, rVert, squash, layer) {
        return {
            type: "ELLIPSE",
            layer: layer,
            color: this.config.layers[layer].color,
            center: {x: cx, y: cy},
            major_axis: {x: 0, y: rVert}, 
            ratio: Math.max(0.001, squash), 
            start_param: 0,
            end_param: 6.28318
        };
    },

    createMText: (x, y, text, rotation, layer = "COTATIONS") => ({ 
        type: "MTEXT", layer, color: IspagDxfEngine.config.layers[layer].color, 
        point: {x, y}, height: 25, text, rotation 
    })
};