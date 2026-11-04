import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import {
    forgetCollectionNames,
    readCollectionNames,
    rememberCollectionNames,
} from '../resources/js/lib/collectionNames.ts';

const names = {
    requestUuid: 'first-request',
    adultName: 'David Jones',
    childName: 'Sam Jones',
};

beforeEach(() => {
    const items = new Map();
    globalThis.window = {
        localStorage: {
            getItem: (key) => items.get(key) ?? null,
            setItem: (key, value) => items.set(key, value),
            removeItem: (key) => items.delete(key),
        },
    };
});

test('remembers the latest submitted pair separately for each venue', () => {
    assert.equal(readCollectionNames('venue-a'), null);
    rememberCollectionNames('venue-a', names);
    rememberCollectionNames('venue-b', { ...names, childName: 'Alex Jones' });
    assert.deepEqual(readCollectionNames('venue-a'), names);
    assert.equal(readCollectionNames('venue-b').childName, 'Alex Jones');
    rememberCollectionNames('venue-a', { ...names, childName: 'Chris Jones' });
    assert.equal(readCollectionNames('venue-a').childName, 'Chris Jones');
});

test('an unmatched request clears both names without clearing other browser data', () => {
    rememberCollectionNames('venue-a', names);
    rememberCollectionNames('venue-b', names);
    window.localStorage.setItem('unrelated', 'keep');
    forgetCollectionNames('venue-a', names.requestUuid);
    assert.equal(readCollectionNames('venue-a'), null);
    assert.deepEqual(readCollectionNames('venue-b'), names);
    assert.equal(window.localStorage.getItem('unrelated'), 'keep');
});

test('an older request cannot clear a newer saved pair', () => {
    rememberCollectionNames('venue-a', {
        ...names,
        requestUuid: 'new-request',
    });
    forgetCollectionNames('venue-a', 'first-request');
    assert.equal(readCollectionNames('venue-a').requestUuid, 'new-request');
});

test('malformed stored data never fills the form', () => {
    for (const value of [
        'invalid json',
        'null',
        '[]',
        '{"adultName":42}',
        JSON.stringify({ ...names, childName: '' }),
    ]) {
        window.localStorage.setItem('collection-request:names:venue-a', value);
        assert.equal(readCollectionNames('venue-a'), null);
    }
});

test('blocked browser storage does not interrupt the request flow', () => {
    Object.defineProperty(window, 'localStorage', {
        get() {
            throw new Error('Storage blocked');
        },
    });
    assert.equal(readCollectionNames('venue-a'), null);
    assert.doesNotThrow(() => rememberCollectionNames('venue-a', names));
    assert.doesNotThrow(() =>
        forgetCollectionNames('venue-a', names.requestUuid),
    );
});
