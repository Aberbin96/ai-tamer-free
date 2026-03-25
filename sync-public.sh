#!/bin/bash

# Configuration
PLUGIN_NAME="ai-tamer"
SOURCE_DIR="./"
TARGET_DIR="../ai-tamer-free" # Change this if you want a different location

echo "🚀 Starting synchronization for Public Repo..."

# 1. Create target directory if it doesn't exist
if [ ! -d "$TARGET_DIR" ]; then
    echo "📂 Creating target directory: $TARGET_DIR"
    mkdir -p "$TARGET_DIR"
fi

# 2. Sync files using .distignore
echo "📂 Syncing production files..."
rsync -rc --delete --exclude-from=.distignore "$SOURCE_DIR" "$TARGET_DIR/"

# 3. Clean up extra files that might not be in .distignore but shouldn't be public
# (Add specific removals here if needed)
rm -f "$TARGET_DIR/sync-public.sh" # Don't include the sync script itself in the public repo

# 4. Check for Git initialization in target
if [ ! -d "$TARGET_DIR/.git" ]; then
    echo "📜 Initializing Git in public folder..."
    cd "$TARGET_DIR"
    git init
    # git remote add origin <YOUR_PUBLIC_REPO_URL>
    echo "⚠️  Remember to add your public remote and push!"
    cd - > /dev/null
fi

echo "✅ Sync complete! Your public-ready files are in: $TARGET_DIR"
echo "👉 Next steps: Review the files in $TARGET_DIR, then 'git add', 'git commit' and 'git push'."
