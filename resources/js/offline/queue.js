/* Daway offline — operation builder/queue helpers. */
import { db } from './db.js';

export function makeOp(op_type, payload) {
    return {
        uuid: (crypto && crypto.randomUUID) ? crypto.randomUUID() : 'op-' + Date.now() + '-' + Math.random().toString(16).slice(2),
        op_type,
        payload,
        client_updated_at: new Date().toISOString(),
    };
}

export function queueAddOp(op_type, payload) {
    const op = makeOp(op_type, payload);
    return db.queueAdd(op).then(() => op);
}
