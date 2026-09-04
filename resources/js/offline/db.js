/* Daway offline — IndexedDB wrapper (promise-based). */
const DB_NAME = 'daway-offline';
const DB_VERSION = 1;

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('inventory')) {
                const s = db.createObjectStore('inventory', { keyPath: 'id' });
                s.createIndex('updated_at', 'updated_at');
            }
            if (!db.objectStoreNames.contains('medicines')) {
                const s = db.createObjectStore('medicines', { keyPath: 'id' });
                s.createIndex('updated_at', 'updated_at');
            }
            if (!db.objectStoreNames.contains('inquiries')) {
                const s = db.createObjectStore('inquiries', { keyPath: 'id' });
                s.createIndex('status', 'status');
            }
            if (!db.objectStoreNames.contains('queue')) {
                db.createObjectStore('queue', { keyPath: 'uuid' });
            }
            if (!db.objectStoreNames.contains('meta')) {
                db.createObjectStore('meta', { keyPath: 'key' });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
    return dbPromise;
}

function tx(store, mode, fn) {
    return openDb().then((db) => new Promise((resolve, reject) => {
        const transaction = db.transaction(store, mode);
        const objectStore = transaction.objectStore(store);
        const result = fn(objectStore);
        transaction.oncomplete = () => resolve(result && result.__value !== undefined ? result.__value : result);
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
    }));
}

function requestToPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export const db = {
    getAll(store) {
        return openDb().then((d) => requestToPromise(
            d.transaction(store, 'readonly').objectStore(store).getAll()
        ));
    },
    put(store, value) {
        return tx(store, 'readwrite', (s) => s.put(value));
    },
    putAll(store, values) {
        return tx(store, 'readwrite', (s) => values.forEach((v) => s.put(v)));
    },
    /* Replace a store's contents with rows, removing stale rows not present in the payload. */
    bulkReplace(store, rows) {
        return openDb().then((d) => new Promise((resolve, reject) => {
            const transaction = d.transaction(store, 'readwrite');
            const objectStore = transaction.objectStore(store);
            const keep = new Set(rows.map((r) => String(r.id)));
            objectStore.getAllKeys().onsuccess = (event) => {
                const keys = event.target.result || [];
                keys.filter((k) => !keep.has(String(k))).forEach((k) => objectStore.delete(k));
            };
            rows.forEach((row) => objectStore.put(row));
            transaction.oncomplete = () => resolve(true);
            transaction.onerror = () => reject(transaction.error);
        }));
    },
    get(store, key) {
        return openDb().then((d) => requestToPromise(
            d.transaction(store, 'readonly').objectStore(store).get(key)
        ));
    },
    delete(store, key) {
        return tx(store, 'readwrite', (s) => s.delete(key));
    },
    count(store) {
        return openDb().then((d) => requestToPromise(
            d.transaction(store, 'readonly').objectStore(store).count()
        ));
    },
    queueAdd(op) {
        return tx('queue', 'readwrite', (s) => s.put(op));
    },
    queueAll() {
        return db.getAll('queue');
    },
    queueDelete(uuid) {
        return tx('queue', 'readwrite', (s) => s.delete(uuid));
    },
    metaGet(key) {
        return db.get('meta', key).then((row) => (row ? row.value : null));
    },
    metaSet(key, value) {
        return tx('meta', 'readwrite', (s) => s.put({ key, value }));
    },
};
