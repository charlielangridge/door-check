import assert from 'node:assert/strict';
import { test } from 'node:test';
import { effectScope, ref } from 'vue';
import { useStaffPhoto } from '../resources/js/composables/useStaffPhoto.ts';

function photoScope(t, source = '/staff/venue/media/1', pending = false) {
    const scope = effectScope();
    const src = ref(source);
    const waiting = ref(pending);
    const photo = scope.run(() => useStaffPhoto(src, waiting));
    t.after(() => scope.stop());
    return { ...photo, src, waiting, scope };
}

test('a photo stays loading until its image has loaded', (t) => {
    const photo = photoScope(t);
    assert.equal(photo.state.value, 'loading');
    photo.loaded();
    assert.equal(photo.state.value, 'loaded');
});

test('a pending identity waits for a photo and a missing photo is unavailable', (t) => {
    const photo = photoScope(t, null, true);
    assert.equal(photo.state.value, 'loading');
    photo.waiting.value = false;
    assert.equal(photo.state.value, 'unavailable');
    photo.src.value = '/staff/venue/media/2';
    assert.equal(photo.state.value, 'loading');
});

test('a file still being saved is retried and can finish loading', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const photo = photoScope(t);
    photo.failed();
    assert.equal(photo.state.value, 'loading');
    t.mock.timers.tick(2000);
    assert.equal(photo.imageSrc.value, '/staff/venue/media/1?photo_retry=1');
    photo.loaded();
    t.mock.timers.tick(10000);
    assert.equal(photo.state.value, 'loaded');
});

test('a permanently missing file stops retrying and becomes unavailable', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const photo = photoScope(t);
    for (let attempt = 0; attempt < 5; attempt++) {
        photo.failed();
        t.mock.timers.tick(2000);
    }
    photo.failed();
    assert.equal(photo.state.value, 'unavailable');
    assert.equal(photo.imageSrc.value, '/staff/venue/media/1?photo_retry=5');
});

test('selecting a different person resets a failed photo and its retries', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const photo = photoScope(t);
    photo.failed();
    photo.src.value = '/staff/venue/media/2';
    t.mock.timers.tick(2000);
    assert.equal(photo.imageSrc.value, '/staff/venue/media/2');
    assert.equal(photo.state.value, 'loading');
    photo.loaded();
    assert.equal(photo.state.value, 'loaded');
});

test('leaving the page cancels pending photo retries', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const photo = photoScope(t);
    photo.failed();
    photo.scope.stop();
    t.mock.timers.tick(2000);
    assert.equal(photo.imageSrc.value, '/staff/venue/media/1');
});
