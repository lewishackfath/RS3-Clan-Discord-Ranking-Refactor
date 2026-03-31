import dotenv from 'dotenv';
import express from 'express';
import {
  Client,
  GatewayIntentBits,
  Partials,
  PermissionsBitField,
} from 'discord.js';

dotenv.config({ path: new URL('../../.env', import.meta.url).pathname });

const app = express();
const port = Number(process.env.BOT_PORT || 3100);
const host = process.env.BOT_HOST || '127.0.0.1';
const sharedSecret = process.env.BOT_SHARED_SECRET || '';
const guildId = process.env.DISCORD_GUILD_ID;

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMembers,
  ],
  partials: [Partials.GuildMember],
});

function requireSecret(req, res, next) {
  const header = req.get('X-Bot-Shared-Secret') || '';
  if (!sharedSecret || header !== sharedSecret) {
    return res.status(403).json({ error: 'forbidden' });
  }
  next();
}

async function getGuild() {
  if (!guildId) throw new Error('DISCORD_GUILD_ID is not configured');
  const guild = await client.guilds.fetch(guildId);
  await guild.roles.fetch();
  await guild.members.fetch({ user: client.user.id });
  return guild;
}

function sortedRoles(guild) {
  return [...guild.roles.cache.values()]
    .filter(role => role.id !== guild.id)
    .sort((a, b) => b.position - a.position)
    .map(role => ({
      id: role.id,
      name: role.name,
      position: role.position,
      managed: role.managed,
      mentionable: role.mentionable,
      color: role.hexColor,
    }));
}

async function buildSummary() {
  const guild = await getGuild();
  const me = await guild.members.fetchMe();
  const roles = sortedRoles(guild);
  const botHighestRole = me.roles.highest;
  const requiredPermissions = [
    ['ManageRoles', PermissionsBitField.Flags.ManageRoles],
    ['ViewChannel', PermissionsBitField.Flags.ViewChannel],
  ];

  const missingPermissions = requiredPermissions
    .filter(([, permission]) => !me.permissions.has(permission))
    .map(([name]) => name);

  return {
    guild: {
      id: guild.id,
      name: guild.name,
      member_count: guild.memberCount,
    },
    bot: {
      user_id: client.user.id,
      username: client.user.username,
      highest_role: botHighestRole ? {
        id: botHighestRole.id,
        name: botHighestRole.name,
        position: botHighestRole.position,
      } : null,
      missing_permissions: missingPermissions,
    },
    roles,
  };
}

app.use(express.json());
app.use(requireSecret);

app.get('/health', async (req, res) => {
  try {
    const guild = guildId ? await client.guilds.fetch(guildId) : null;
    return res.json({
      ok: client.isReady(),
      user: client.user ? { id: client.user.id, username: client.user.username } : null,
      guild: guild ? { id: guild.id, name: guild.name } : null,
    });
  } catch (error) {
    return res.status(500).json({ error: String(error.message || error) });
  }
});

app.get('/guild/summary', async (req, res) => {
  try {
    const summary = await buildSummary();
    return res.json(summary);
  } catch (error) {
    return res.status(500).json({ error: String(error.message || error) });
  }
});

app.get('/guild/member/:userId', async (req, res) => {
  try {
    const guild = await getGuild();
    const member = await guild.members.fetch(req.params.userId);
    return res.json({
      user_id: member.id,
      username: member.user.username,
      global_name: member.user.globalName,
      nickname: member.nickname,
      display_name: member.displayName,
      role_ids: member.roles.cache
        .filter(role => role.id !== guild.id)
        .sort((a, b) => b.position - a.position)
        .map(role => role.id),
    });
  } catch (error) {
    return res.status(404).json({ error: String(error.message || error) });
  }
});


app.post('/guild/roles', async (req, res) => {
  try {
    const guild = await getGuild();
    const name = String(req.body?.name || '').trim();
    if (!name) {
      return res.status(422).json({ error: 'Role name is required' });
    }

    const role = await guild.roles.create({
      name,
      reason: 'Admin role mapping setup',
      mentionable: false,
    });

    return res.status(201).json({
      id: role.id,
      name: role.name,
      position: role.position,
      managed: role.managed,
    });
  } catch (error) {
    return res.status(500).json({ error: String(error.message || error) });
  }
});

app.get('/guild/members', async (req, res) => {
  try {
    const guild = await getGuild();
    await guild.members.fetch();
    const members = [...guild.members.cache.values()]
      .filter(member => !member.user.bot)
      .sort((a, b) => a.displayName.localeCompare(b.displayName))
      .map(member => ({
        user_id: member.id,
        username: member.user.username,
        global_name: member.user.globalName,
        nickname: member.nickname,
        display_name: member.displayName,
        role_ids: member.roles.cache
          .filter(role => role.id !== guild.id)
          .map(role => role.id),
      }));
    return res.json({ members });
  } catch (error) {
    return res.status(500).json({ error: String(error.message || error) });
  }
});

client.once('ready', () => {
  console.log(`Bot ready as ${client.user.tag}`);
  app.listen(port, host, () => {
    console.log(`Bot service listening on http://${host}:${port}`);
  });
});

client.login(process.env.DISCORD_BOT_TOKEN);
