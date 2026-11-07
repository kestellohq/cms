<script>
export default { layout: 'Site' };
</script>

<script setup>
import { Head, Link } from '@kestello/app';

defineProps({ kestelloVersion: { type: String, default: '' } });
</script>

<template>
    <Head title="About" />
    <div class="site-wrap">
        <div class="wall">
            <section class="plate plate--light page p-story" style="grid-row: 1 / span 7; padding: 48px 56px" aria-labelledby="about">
                <i class="plate__screw" /><i class="plate__screw" /><i class="plate__screw" /><i class="plate__screw" />
                <h1 id="about">About this site</h1>
                <div class="prose">
                    <p class="lead">This site runs on Kestello, a self-hosted content management system you can install, run and update without a developer.</p>
                    <p>
                        The front page shows sample content for a fictional bakery. It demonstrates the base theme: enamel-sign plates on a modular wall,
                        coloured from a single setting. Replace it with your own words, hours and address.
                    </p>
                    <h2>Make it yours</h2>
                    <ul>
                        <li>Sign in to the admin to manage people, settings and the health of the site.</li>
                        <li>Change the enamel colour, type and corners in Appearance → Styles, or override <code>--plate</code> in a child theme.</li>
                        <li>Add features with plugins and change the look with themes, without touching core.</li>
                    </ul>
                </div>
            </section>
            <section class="plate plate--deep p-contact" style="grid-row: 1 / span 5" aria-labelledby="running">
                <i class="plate__screw" /><i class="plate__screw" /><i class="plate__screw" /><i class="plate__screw" />
                <h2 id="running">Running Kestello {{ kestelloVersion }}</h2>
                <p>Open the admin to check your site's health and settings.</p>
                <Link href="/admin" class="plate-action" style="align-self: flex-start">Open the admin</Link>
            </section>
        </div>
    </div>
</template>
