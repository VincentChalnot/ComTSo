<template>
  <div class="chat-widget">
    <div class="chat-messages" ref="messagesContainer">
      <div
        v-for="message in messages"
        :key="message.id"
        class="message-item"
        :class="{ 'is-own-message': isOwnMessage(message) }"
      >
        <div class="message-header">
          <strong class="message-author">{{ message.author_username }}</strong>
          <span class="message-time is-size-7">{{ formatTime(message.created_at) }}</span>
        </div>
        <div class="message-content">{{ message.content }}</div>
      </div>
    </div>

    <div class="chat-input-container">
      <form @submit.prevent="sendMessage" class="field has-addons">
        <div class="control is-expanded">
          <input
            v-model="newMessage"
            type="text"
            class="input"
            placeholder="Type a message..."
            :disabled="sending"
            maxlength="500"
          />
        </div>
        <div class="control">
          <button type="submit" class="button is-primary" :disabled="!newMessage.trim() || sending">
            <span v-if="!sending">Send</span>
            <span v-else>Sending...</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'ChatWidget',
  props: {
    initialMessages: {
      type: Array,
      default: () => []
    },
    apiMessagesUrl: {
      type: String,
      required: true
    },
    apiSendUrl: {
      type: String,
      required: true
    },
    mercureUrl: {
      type: String,
      required: true
    },
    currentUsername: {
      type: String,
      default: ''
    }
  },
  data() {
    return {
      messages: [...this.initialMessages],
      newMessage: '',
      sending: false,
      eventSource: null
    };
  },
  methods: {
    async sendMessage() {
      if (!this.newMessage.trim() || this.sending) return;

      this.sending = true;
      try {
        const response = await axios.post(this.apiSendUrl, {
          content: this.newMessage.trim()
        });

        // Message will be received via Mercure, so just clear input
        this.newMessage = '';
      } catch (error) {
        console.error('Failed to send message:', error);
        alert('Failed to send message. Please try again.');
      } finally {
        this.sending = false;
      }
    },
    isOwnMessage(message) {
      return this.currentUsername && message.author_username === this.currentUsername;
    },
    formatTime(timestamp) {
      if (!timestamp) return '';
      const date = new Date(timestamp);
      const now = new Date();
      const diffMs = now - date;
      const diffMins = Math.floor(diffMs / 60000);

      if (diffMins < 1) return 'Just now';
      if (diffMins < 60) return `${diffMins}m ago`;
      if (diffMins < 1440) return `${Math.floor(diffMins / 60)}h ago`;

      return date.toLocaleString();
    },
    scrollToBottom() {
      this.$nextTick(() => {
        const container = this.$refs.messagesContainer;
        if (container) {
          container.scrollTop = container.scrollHeight;
        }
      });
    },
    connectToMercure() {
      if (!this.mercureUrl) {
        console.warn('Mercure URL not provided, real-time updates disabled');
        return;
      }

      const url = new URL(this.mercureUrl);
      url.searchParams.append('topic', 'chat');

      this.eventSource = new EventSource(url);

      this.eventSource.onmessage = (event) => {
        try {
          const message = JSON.parse(event.data);

          // Check if message already exists
          const exists = this.messages.some(m => m.id === message.id);
          if (!exists) {
            this.messages.push(message);
            this.scrollToBottom();
          }
        } catch (error) {
          console.error('Failed to parse message:', error);
        }
      };

      this.eventSource.onerror = (error) => {
        console.error('Mercure connection error:', error);
        // Attempt to reconnect after 5 seconds
        setTimeout(() => {
          if (this.eventSource) {
            this.eventSource.close();
            this.connectToMercure();
          }
        }, 5000);
      };
    },
    async loadRecentMessages() {
      try {
        const response = await axios.get(this.apiMessagesUrl);
        this.messages = response.data;
        this.scrollToBottom();
      } catch (error) {
        console.error('Failed to load messages:', error);
      }
    }
  },
  mounted() {
    this.scrollToBottom();
    this.connectToMercure();

    // Poll for new messages every 30 seconds as fallback
    this.pollInterval = setInterval(() => {
      if (!this.eventSource || this.eventSource.readyState !== EventSource.OPEN) {
        this.loadRecentMessages();
      }
    }, 30000);
  },
  beforeUnmount() {
    if (this.eventSource) {
      this.eventSource.close();
    }
    if (this.pollInterval) {
      clearInterval(this.pollInterval);
    }
  }
};
</script>

<style scoped>
.chat-widget {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: white;
  border-radius: 4px;
}

.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 1rem;
  min-height: 400px;
  max-height: 600px;
}

.message-item {
  margin-bottom: 1rem;
  padding: 0.75rem;
  background: #f5f5f5;
  border-radius: 8px;
  border-left: 3px solid #3273dc;
}

.message-item.is-own-message {
  background: #e8f4fd;
  border-left-color: #48c774;
  margin-left: 2rem;
}

.message-header {
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}

.message-author {
  color: #3273dc;
}

.message-time {
  color: #7a7a7a;
}

.message-content {
  word-wrap: break-word;
  white-space: pre-wrap;
}

.chat-input-container {
  padding: 1rem;
  border-top: 1px solid #dbdbdb;
  background: white;
}

[data-theme="dark"] .chat-widget {
  background: #2a2a2a;
}

[data-theme="dark"] .message-item {
  background: #3a3a3a;
  color: #f5f5f5;
}

[data-theme="dark"] .message-item.is-own-message {
  background: #2d4a5c;
}

[data-theme="dark"] .chat-input-container {
  background: #2a2a2a;
  border-top-color: #4a4a4a;
}
</style>
